---
label: Configuration
icon: gear
order: 80
---

# Configuration

This section covers all aspects of configuring Laravel Ingest, from basic setup to advanced security considerations.

## Security Best Practices

When dealing with file uploads and data imports, security should be a top priority. Here are the recommended security configurations for production environments.

### File Upload Security

#### 1. Restrict File Types

```php
// config/ingest.php
'allowed_file_types' => [
    'csv',
    'xlsx',
    'xls',
    'txt',
],
```

#### 2. File Size Limits

```php
// config/ingest.php
'max_file_size' => 10 * 1024 * 1024, // 10MB in production
```

#### 3. Virus Scanning (Optional but Recommended)

```php
// In your Importer class
use Illuminate\Http\UploadedFile;

public function getConfig(): IngestConfig
{
    return IngestConfig::for(Product::class)
        ->beforeImport(function(UploadedFile $file) {
            // Integrate with virus scanning service
            if (app()->environment('production')) {
                $this->scanForMalware($file);
            }
        });
}

private function scanForMalware(UploadedFile $file): void
{
    // Example: ClamAV integration
    $clamav = new \Xenolope\Quahog\Client(
        new \Xenolope\Quahog\Socket('127.0.0.1', 3310)
    );
    
    $result = $clamav->scanFile($file->getPathname());
    
    if ($result->isInfected()) {
        throw new SecurityException('File contains malware');
    }
}
```

### Data Validation Security

#### 1. Input Sanitization

```php
->beforeRow(function(array &$row) {
    // Remove potentially dangerous content
    foreach ($row as $key => $value) {
        if (is_string($value)) {
            $row[$key] = strip_tags($value);
            $row[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }
    }
})
```

#### 2. SQL Injection Prevention

```php
// Always use parameter binding in custom queries
->afterRow(function($model, array $row) {
    // ❌ BAD: Direct string interpolation
    // DB::statement("UPDATE products SET notes = '{$row['notes']}' WHERE id = {$model->id}");
    
    // ✅ GOOD: Parameter binding
    DB::statement(
        "UPDATE products SET notes = ? WHERE id = ?",
        [$row['notes'], $model->id]
    );
})
```

#### 3. Data Type Validation

```php
->validate([
    'email' => 'required|email|max:255',
    'phone' => 'nullable|string|max:20|regex:/^\+?[1-9]\d{1,14}$/',
    'price' => 'required|numeric|min:0|max:999999.99',
    'quantity' => 'required|integer|min:0|max:999999',
    'website' => 'nullable|url|max:2048',
    'ip_address' => 'nullable|ip',
])
```

### Access Control

#### 1. Permission-Based Import Access

```php
// In your ImportController
public function store(Request $request)
{
    $this->authorize('import', Product::class);
    
    // Additional role-based checks
    if (!auth()->user()->hasRole('data_manager')) {
        abort(403, 'Insufficient permissions for data import');
    }
    
    // Proceed with import
}
```

#### 2. Rate Limiting

```php
// routes/web.php or routes/api.php
Route::middleware([
    'auth',
    'throttle:5,1', // 5 imports per minute
])->group(function () {
    Route::post('/import/products', [ProductImportController::class, 'store']);
});
```

#### 3. Audit Logging

```php
->beforeImport(function(UploadedFile $file) {
    // Log import attempt
    activity()
        ->causedBy(auth()->user())
        ->performedOn(new Product())
        ->withProperties([
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'ip_address' => request()->ip(),
        ])
        ->log('product_import_started');
})

->afterImport(function(ImportResult $result) {
    // Log import completion
    activity()
        ->causedBy(auth()->user())
        ->performedOn(new Product())
        ->withProperties([
            'total_rows' => $result->totalRows,
            'successful_rows' => $result->successfulRows,
            'failed_rows' => $result->failedRows,
            'duration' => $result->duration,
        ])
        ->log('product_import_completed');
})
```

### Environment-Specific Security

#### 1. Production Configuration

```php
// config/ingest.php - Production
'allowed_file_types' => ['csv', 'xlsx'],
'max_file_size' => 10 * 1024 * 1024, // 10MB
'log_rows' => true, // Enable for audit trail
'queue_connection' => 'redis', // Use reliable queue
'transaction_mode' => 'chunk', // Safer imports
'chunk_size' => 100, // Smaller chunks for reliability
```

#### 2. Development Configuration

```php
// config/ingest.php - Local Development
'allowed_file_types' => ['csv', 'xlsx', 'txt', 'json'],
'max_file_size' => 50 * 1024 * 1024, // 50MB for testing
'log_rows' => true, // Enable for debugging
'queue_connection' => 'database', // Simple queue for dev
'transaction_mode' => 'row', // Maximum safety
'chunk_size' => 10, // Small chunks for debugging
```

#### 3. Staging Configuration

```php
// config/ingest.php - Staging
'allowed_file_types' => ['csv', 'xlsx'],
'max_file_size' => 20 * 1024 * 1024, // 20MB
'log_rows' => true,
'queue_connection' => 'redis',
'transaction_mode' => 'chunk',
'chunk_size' => 50,
```

### Data Privacy and Compliance

#### 1. GDPR Considerations

```php
->beforeRow(function(array &$row) {
    // Remove or anonymize sensitive data if not needed
    $sensitiveFields = ['ssn', 'credit_card', 'bank_account'];
    
    foreach ($sensitiveFields as $field) {
        if (isset($row[$field])) {
            unset($row[$field]);
        }
    }
    
    // Log data processing for compliance
    if (isset($row['email'])) {
        Log::info('Processing user data', [
            'email_hash' => hash('sha256', $row['email']),
            'purpose' => 'product_import',
            'legal_basis' => 'legitimate_interest',
        ]);
    }
})
```

#### 2. Data Retention Policies

```php
// In your Importer class
public function getConfig(): IngestConfig
{
    return IngestConfig::for(Product::class)
        ->afterImport(function(ImportResult $result) {
            // Clean up temporary files after processing
            $this->cleanupTempFiles();
            
            // Archive import logs after 30 days
            $this->archiveImportLogs($result->importId);
        });
}
```

### Security Headers and CORS

```php
// In your ImportController
public function store(Request $request)
{
    // Set security headers
    return response()->json([
        'message' => 'Import started',
        'import_id' => $importId,
    ], 202)->withHeaders([
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'X-XSS-Protection' => '1; mode=block',
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
    ]);
}
```

### Monitoring and Alerting

#### 1. Security Event Monitoring

```php
->beforeRow(function(array &$row) {
    // Monitor for suspicious patterns
    $suspiciousPatterns = [
        '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi',
        '/javascript:/i',
        '/vbscript:/i',
        '/onload\s*=/i',
        '/onerror\s*=/i',
    ];
    
    foreach ($row as $key => $value) {
        if (is_string($value)) {
            foreach ($suspiciousPatterns as $pattern) {
                if (preg_match($pattern, $value)) {
                    // Log security event
                    Log::alert('Suspicious content detected', [
                        'field' => $key,
                        'pattern' => $pattern,
                        'user_id' => auth()->id(),
                        'ip_address' => request()->ip(),
                    ]);
                    
                    // Optionally block the import
                    throw new SecurityException('Suspicious content detected');
                }
            }
        }
    }
})
```

#### 2. Performance Monitoring

```php
->afterImport(function(ImportResult $result) {
    // Alert on unusual import patterns
    if ($result->failedRows > $result->totalRows * 0.1) {
        // More than 10% failure rate - alert administrators
        Notification::route('mail', ['admin@company.com'])
            ->notify(new HighFailureRateAlert($result));
    }
    
    if ($result->duration > 300) { // 5 minutes
        // Unusually long import time
        Notification::route('slack', config('services.slack.webhook'))
            ->notify(new SlowImportAlert($result));
    }
})
```

### Security Checklist

- [ ] File type restrictions configured
- [ ] File size limits enforced
- [ ] Input validation and sanitization implemented
- [ ] SQL injection prevention measures in place
- [ ] Access control and permissions configured
- [ ] Rate limiting applied to import endpoints
- [ ] Comprehensive audit logging enabled
- [ ] Environment-specific security configurations
- [ ] Data privacy and compliance measures implemented
- [ ] Security headers and CORS properly configured
- [ ] Monitoring and alerting systems active
- [ ] Regular security reviews and penetration testing

### Recommended Security Packages

```bash
# Additional security packages for Laravel
composer require spatie/laravel-security
composer require laravel/fortify
composer require spatie/laravel-activitylog
```

Remember: Security is an ongoing process, not a one-time configuration. Regularly review and update your security measures to protect against new threats.