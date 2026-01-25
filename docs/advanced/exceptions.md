---
label: Exceptions
order: 50
---

# Exceptions

Laravel Ingest provides specific exception classes for different error scenarios. Understanding these exceptions helps you implement proper error handling in your application.

## Exception Overview

| Exception | When Thrown |
|-----------|-------------|
| `SourceException` | Problems reading from data source |
| `DefinitionNotFoundException` | Importer slug not found |
| `InvalidConfigurationException` | Invalid IngestConfig setup |
| `ConcurrencyException` | Concurrent operation conflicts |
| `NoFailedRowsException` | Retry attempted with no failed rows |
| `FileProcessingException` | File validation or processing errors |

---

## SourceException

Thrown when there's a problem reading data from a source (file not found, connection failed, etc.).

```php
use LaravelIngest\Exceptions\SourceException;

try {
    $run = Ingest::start('product-importer', 'missing-file.csv');
} catch (SourceException $e) {
    Log::error('Source error: ' . $e->getMessage());
    // Handle: file not found, FTP connection failed, URL unreachable, etc.
}
```

**Common causes:**
- File does not exist at specified path
- FTP/SFTP connection or authentication failed
- URL is unreachable or returned an error
- Insufficient permissions to read file

---

## DefinitionNotFoundException

Thrown when attempting to use an importer that hasn't been registered in `config/ingest.php`.

```php
use LaravelIngest\Exceptions\DefinitionNotFoundException;

try {
    $run = Ingest::start('non-existent-importer');
} catch (DefinitionNotFoundException $e) {
    // "No importer found with the slug 'non-existent-importer'. 
    //  Please check your spelling or run 'php artisan ingest:list' 
    //  to see available importers."
}
```

**Solution:** Ensure the importer is registered:

```php
// config/ingest.php
'importers' => [
    'product-importer' => App\Ingest\ProductImporter::class,
],
```

---

## InvalidConfigurationException

Thrown when an `IngestConfig` is invalid or missing required settings.

```php
use LaravelIngest\Exceptions\InvalidConfigurationException;

try {
    $run = Ingest::start('misconfigured-importer');
} catch (InvalidConfigurationException $e) {
    Log::error('Config error: ' . $e->getMessage());
}
```

**Common causes:**
- Missing `for()` model class
- Missing `fromSource()` definition
- Invalid source type options
- Incompatible configuration combinations

---

## ConcurrencyException

Thrown when concurrent operations conflict with each other. Includes factory methods for specific scenarios.

```php
use LaravelIngest\Exceptions\ConcurrencyException;

try {
    $run = Ingest::retry($originalRun);
} catch (ConcurrencyException $e) {
    // Another retry is already in progress
}
```

### Factory Methods

```php
// Thrown when a retry is already in progress for the same run
ConcurrencyException::duplicateRetryAttempt(int $originalRunId)
// "A retry attempt for run {id} is already in progress or completed."

// Thrown when a lock cannot be acquired within the timeout
ConcurrencyException::lockTimeout(int $runId, int $timeout)
// "Could not acquire lock for run {id} within {timeout} seconds."

// Thrown on optimistic locking conflicts
ConcurrencyException::conflictingUpdate(int $runId, ?Throwable $previous)
// "Conflicting update detected for run {id}"
```

**Handling:**

```php
try {
    $run = Ingest::retry($originalRun);
} catch (ConcurrencyException $e) {
    if (str_contains($e->getMessage(), 'already in progress')) {
        return response()->json(['error' => 'A retry is already running'], 409);
    }
    throw $e;
}
```

---

## NoFailedRowsException

Thrown when attempting to retry an import run that has no failed rows.

```php
use LaravelIngest\Exceptions\NoFailedRowsException;

try {
    $run = Ingest::retry($originalRun);
} catch (NoFailedRowsException $e) {
    // "The original run has no failed rows to retry."
}
```

**Solution:** Check for failed rows before retrying:

```php
if ($originalRun->failed_rows > 0) {
    $run = Ingest::retry($originalRun);
} else {
    return response()->json(['message' => 'No failed rows to retry']);
}
```

---

## FileProcessingException

Thrown during file validation and processing. Includes factory methods for specific file-related errors.

```php
use LaravelIngest\Exceptions\FileProcessingException;

try {
    $run = Ingest::start('importer', $uploadedFile);
} catch (FileProcessingException $e) {
    return response()->json(['error' => $e->getMessage()], 422);
}
```

### Factory Methods

```php
// File exceeds maximum allowed size
FileProcessingException::fileTooLarge(int $size, int $maxSize)
// "File size ({size} bytes) exceeds maximum allowed size ({maxSize} bytes)."

// File MIME type not in allowed list
FileProcessingException::invalidMimeType(string $mimeType, array $allowed)
// "File type '{mimeType}' is not allowed. Allowed types: text/csv, ..."

// Potentially malicious content detected
FileProcessingException::maliciousContent()
// "File contains potentially malicious content."

// File cannot be read
FileProcessingException::unreadableFile(string $path, ?Throwable $previous)
// "Unable to read file at path: {path}"

// File is corrupted or invalid format
FileProcessingException::corruptedFile(string $path, ?Throwable $previous)
// User-friendly message about corrupted file
```

**Handling upload errors:**

```php
use LaravelIngest\Exceptions\FileProcessingException;

try {
    $run = Ingest::start('csv-importer', $request->file('import'));
} catch (FileProcessingException $e) {
    $message = $e->getMessage();
    
    if (str_contains($message, 'too large')) {
        return back()->withErrors(['file' => 'File is too large. Maximum size is 50MB.']);
    }
    
    if (str_contains($message, 'not allowed')) {
        return back()->withErrors(['file' => 'Please upload a CSV or Excel file.']);
    }
    
    return back()->withErrors(['file' => 'Could not process the file.']);
}
```

---

## Global Exception Handling

You can handle Ingest exceptions globally in your exception handler:

```php
// app/Exceptions/Handler.php (Laravel 10 and earlier)
// or bootstrap/app.php (Laravel 11+)

use LaravelIngest\Exceptions\DefinitionNotFoundException;
use LaravelIngest\Exceptions\FileProcessingException;
use LaravelIngest\Exceptions\ConcurrencyException;

// Laravel 11+
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->render(function (DefinitionNotFoundException $e) {
        return response()->json([
            'error' => 'Importer not found',
            'message' => $e->getMessage(),
        ], 404);
    });

    $exceptions->render(function (FileProcessingException $e) {
        return response()->json([
            'error' => 'File processing failed',
            'message' => $e->getMessage(),
        ], 422);
    });

    $exceptions->render(function (ConcurrencyException $e) {
        return response()->json([
            'error' => 'Operation conflict',
            'message' => $e->getMessage(),
        ], 409);
    });
})
```

---

## Exception Inheritance

All Ingest exceptions extend PHP's base `Exception` class:

```
Exception
├── SourceException
├── DefinitionNotFoundException
├── InvalidConfigurationException
├── ConcurrencyException
├── NoFailedRowsException
└── FileProcessingException
```

This allows you to catch all Ingest exceptions with a single catch block if needed:

```php
use LaravelIngest\Exceptions\{
    SourceException,
    DefinitionNotFoundException,
    InvalidConfigurationException,
    ConcurrencyException,
    NoFailedRowsException,
    FileProcessingException,
};

try {
    $run = Ingest::start($slug, $payload);
} catch (DefinitionNotFoundException $e) {
    // Handle missing importer
} catch (FileProcessingException $e) {
    // Handle file issues
} catch (SourceException $e) {
    // Handle source reading issues  
} catch (\Exception $e) {
    // Handle any other exception
}
```
