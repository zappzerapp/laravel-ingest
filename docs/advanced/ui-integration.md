---
label: UI Integration
order: 60
templating: false
---

# UI Integration Guide

Laravel Ingest provides a full REST API, making it easy to build rich, real-time import interfaces in React, Vue, or Livewire.

This guide outlines the standard flow for building an import UI.

## The Workflow

1.  **Upload**: User selects a file. You `POST` it to the API.
2.  **Receive ID**: The API returns an `IngestRun` object with an `id` and status `processing`.
3.  **Poll**: You query the status endpoint every X seconds.
4.  **Finish**: When status is `completed` (or `failed`), you stop polling and show the result.

## Example: Vue.js Component

Here is a simplified example using Vue 3 and Axios.

```javascript
<script setup>
import { ref } from 'vue';
import axios from 'axios';

const file = ref(null);
const run = ref(null);
const progress = ref(0);
const isPolling = ref(false);

const upload = async () => {
    const formData = new FormData();
    formData.append('file', file.value.files[0]);

    // 1. Start the Import
    const { data } = await axios.post('/api/v1/ingest/upload/product-importer', formData);
    
    run.value = data.data;
    startPolling(run.value.id);
};

const startPolling = (id) => {
    isPolling.value = true;
    
    const interval = setInterval(async () => {
        // 2. Check Status
        const { data } = await axios.get(`/api/v1/ingest/${id}`);
        const currentRun = data.data;
        
        // Update local state
        run.value = currentRun;
        
        // Calculate Percentage
        if (currentRun.progress.total > 0) {
            progress.value = Math.round(
                (currentRun.progress.processed / currentRun.progress.total) * 100
            );
        }

        // 3. Stop Condition
        if (['completed', 'completed_with_errors', 'failed'].includes(currentRun.status)) {
            clearInterval(interval);
            isPolling.value = false;
        }
        
    }, 2000); // Poll every 2 seconds
};
</script>

<template>
    <div>
        <input type="file" @change="file = $event.target" />
        <button @click="upload" :disabled="isPolling">Start Import</button>

        <div v-if="run">
            <h3>Status: {{ run.status }}</h3>
            
            <!-- Progress Bar -->
            <div style="background: #eee; height: 20px; width: 100%;">
                <div :style="{ width: progress + '%', background: 'blue', height: '100%' }"></div>
            </div>
            
            <p>
                Processed: {{ run.progress.processed }} / {{ run.progress.total }}
            </p>
            
            <div v-if="run.status === 'completed_with_errors'">
                ⚠️ Finished with {{ run.progress.failed }} errors.
            </div>
        </div>
    </div>
</template>
```

## Handling Retries

If `run.status` is `completed_with_errors` or `failed`, you can offer a "Retry Failed Rows" button.

```javascript
const retry = async () => {
    const { data } = await axios.post(`/api/v1/ingest/${run.value.id}/retry`);
    // The API returns a NEW run ID. Reset progress and start polling the new ID.
    run.value = data.data;
    progress.value = 0;
    startPolling(run.value.id);
};
```

## Frontend Error Handling

Robust error handling is crucial for a good user experience. Here are comprehensive strategies for handling different types of errors in your frontend.

### Client-Side Validation

Before uploading, validate files on the client side:

```javascript
const validateFile = (file) => {
    const errors = [];
    
    // File size validation (10MB max)
    const maxSize = 10 * 1024 * 1024;
    if (file.size > maxSize) {
        errors.push(`File size (${(file.size / 1024 / 1024).toFixed(2)}MB) exceeds maximum allowed size (10MB)`);
    }
    
    // File type validation
    const allowedTypes = [
        'text/csv',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel'
    ];
    const allowedExtensions = ['.csv', '.xlsx', '.xls'];
    
    const hasValidType = allowedTypes.includes(file.type);
    const hasValidExtension = allowedExtensions.some(ext => 
        file.name.toLowerCase().endsWith(ext)
    );
    
    if (!hasValidType && !hasValidExtension) {
        errors.push('Invalid file type. Please upload a CSV or Excel file.');
    }
    
    return errors;
};

// Usage in upload function
const upload = async () => {
    const selectedFile = file.value.files[0];
    const validationErrors = validateFile(selectedFile);
    
    if (validationErrors.length > 0) {
        errorMessage.value = validationErrors.join(' ');
        return;
    }
    
    // Proceed with upload...
};
```

### Network Error Handling

Handle network-related errors gracefully:

```javascript
const upload = async () => {
    try {
        const formData = new FormData();
        formData.append('file', file.value.files[0]);

        const { data } = await axios.post('/api/v1/ingest/upload/product-importer', formData, {
            timeout: 30000, // 30 second timeout
            headers: {
                'Content-Type': 'multipart/form-data',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        });
        
        run.value = data.data;
        startPolling(run.value.id);
        
    } catch (error) {
        handleUploadError(error);
    }
};

const handleUploadError = (error) => {
    if (error.code === 'ECONNABORTED') {
        errorMessage.value = 'Upload timed out. Please try again with a smaller file.';
    } else if (error.response) {
        // Server responded with error status
        handleApiError(error.response.status, error.response.data);
    } else if (error.request) {
        // Network error (no response received)
        errorMessage.value = 'Network error. Please check your connection and try again.';
    } else {
        // Other error
        errorMessage.value = 'An unexpected error occurred. Please try again.';
    }
};
```

### API Error Response Handling

Different HTTP status codes require different handling:

```javascript
const handleApiError = (status, data) => {
    const errorHandlers = {
        413: () => {
            errorMessage.value = 'File too large. The uploaded file exceeds the maximum allowed size.';
            suggestions.value = ['Compress the file', 'Split into smaller files'];
        },
        422: () => {
            // Validation errors
            if (data.errors) {
                const errorMessages = Object.values(data.errors).flat();
                errorMessage.value = `Validation failed: ${errorMessages.join(', ')}`;
            } else {
                errorMessage.value = data.message || 'Invalid file format or data.';
            }
            suggestions.value = ['Check file format', 'Verify required columns', 'Ensure data is valid'];
        },
        429: () => {
            errorMessage.value = 'Too many requests. Please wait before trying again.';
            suggestions.value = ['Wait a few minutes', 'Reduce import frequency'];
        },
        500: () => {
            errorMessage.value = 'Server error occurred. Please try again later.';
            suggestions.value = ['Try again later', 'Contact support if problem persists'];
        },
        403: () => {
            errorMessage.value = 'Permission denied. You do not have access to import data.';
            suggestions.value = ['Contact administrator', 'Check your permissions'];
        },
        401: () => {
            errorMessage.value = 'Authentication required. Please log in again.';
            suggestions.value = ['Refresh the page', 'Log in again'];
        }
    };
    
    const handler = errorHandlers[status] || errorHandlers[500];
    handler();
};
```

### Polling Error Handling

Handle errors during status polling:

```javascript
const startPolling = (id) => {
    isPolling.value = true;
    let pollCount = 0;
    const maxPolls = 300; // Maximum 10 minutes of polling (2s interval)
    
    const interval = setInterval(async () => {
        try {
            pollCount++;
            
            // Stop polling after maximum attempts
            if (pollCount > maxPolls) {
                clearInterval(interval);
                isPolling.value = false;
                errorMessage.value = 'Import status check timed out. Please refresh the page.';
                return;
            }
            
            const { data } = await axios.get(`/api/v1/ingest/${id}`, {
                timeout: 10000 // 10 second timeout for status checks
            });
            
            const currentRun = data.data;
            run.value = currentRun;
            
            // Calculate percentage
            if (currentRun.progress.total > 0) {
                progress.value = Math.round(
                    (currentRun.progress.processed / currentRun.progress.total) * 100
                );
            }

            // Stop condition
            if (['completed', 'completed_with_errors', 'failed'].includes(currentRun.status)) {
                clearInterval(interval);
                isPolling.value = false;
                
                // Handle failed imports
                if (currentRun.status === 'failed') {
                    handleImportFailure(currentRun);
                }
            }
            
        } catch (error) {
            console.error('Polling error:', error);
            
            // Don't fail immediately on network errors
            if (error.response?.status >= 500 || !error.response) {
                // Server error or network issue - continue polling
                return;
            }
            
            // Client error (4xx) - stop polling
            clearInterval(interval);
            isPolling.value = false;
            handleApiError(error.response?.status || 500, error.response?.data || {});
        }
        
    }, 2000);
};
```

### Import Failure Handling

Handle completed imports with errors:

```javascript
const handleImportFailure = (runData) => {
    if (runData.status === 'completed_with_errors') {
        warningMessage.value = `Import completed with ${runData.progress.failed} errors out of ${runData.progress.total} rows.`;
        
        // Show error details if available
        if (runData.errors && runData.errors.length > 0) {
            errorDetails.value = runData.errors.slice(0, 10); // Show first 10 errors
            showRetryButton.value = true;
        }
    } else if (runData.status === 'failed') {
        errorMessage.value = 'Import failed completely.';
        
        if (runData.errors && runData.errors.length > 0) {
            errorDetails.value = runData.errors;
        }
        
        suggestions.value = [
            'Check the error details above',
            'Fix the data issues in your file',
            'Try importing again',
            'Contact support if problems persist'
        ];
        
        showRetryButton.value = true;
    }
};
```

### User-Friendly Error Display

Create a comprehensive error display component:

```vue
<template>
    <div class="import-container">
        <!-- Error Display -->
        <div v-if="errorMessage" class="error-alert">
            <div class="error-header">
                <svg class="error-icon" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                </svg>
                <h3>Import Error</h3>
                <button @click="clearError" class="dismiss-btn">×</button>
            </div>
            <div class="error-content">
                <p class="error-message">{{ errorMessage }}</p>
                <div v-if="suggestions.length > 0" class="error-suggestions">
                    <h4>Suggestions:</h4>
                    <ul>
                        <li v-for="(suggestion, index) in suggestions" :key="index">{{ suggestion }}</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Warning Display -->
        <div v-if="warningMessage" class="warning-alert">
            <div class="warning-header">
                <svg class="warning-icon" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                </svg>
                <h3>Import Warning</h3>
                <button @click="clearWarning" class="dismiss-btn">×</button>
            </div>
            <div class="warning-content">
                <p class="warning-message">{{ warningMessage }}</p>
            </div>
        </div>

        <!-- Error Details -->
        <div v-if="errorDetails.length > 0" class="error-details">
            <h4>Error Details ({{ errorDetails.length }})</h4>
            <div class="error-list">
                <div v-for="(error, index) in errorDetails" :key="index" class="error-item">
                    <span class="error-row">Row {{ error.row }}:</span>
                    <span class="error-text">{{ error.message }}</span>
                </div>
            </div>
            <button v-if="showRetryButton" @click="retryFailedRows" class="retry-button">
                Retry Failed Rows
            </button>
        </div>
    </div>
</template>

<script setup>
const errorMessage = ref('');
const warningMessage = ref('');
const suggestions = ref([]);
const errorDetails = ref([]);
const showRetryButton = ref(false);

const clearError = () => {
    errorMessage.value = '';
    suggestions.value = [];
};

const clearWarning = () => {
    warningMessage.value = '';
};

const retryFailedRows = async () => {
    try {
        const { data } = await axios.post(`/api/v1/ingest/${run.value.id}/retry`);
        // Reset state and start new import
        run.value = data.data;
        progress.value = 0;
        errorMessage.value = '';
        warningMessage.value = '';
        errorDetails.value = [];
        startPolling(run.value.id);
    } catch (error) {
        errorMessage.value = 'Failed to retry import. Please try again.';
    }
};
</script>

<style scoped>
.error-alert {
    background-color: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 20px;
}

.warning-alert {
    background-color: #fffbeb;
    border: 1px solid #fed7aa;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 20px;
}

.error-header, .warning-header {
    display: flex;
    align-items: center;
    margin-bottom: 12px;
}

.error-icon, .warning-icon {
    width: 20px;
    height: 20px;
    margin-right: 12px;
}

.error-icon {
    color: #dc2626;
}

.warning-icon {
    color: #d97706;
}

.error-header h3, .warning-header h3 {
    margin: 0;
    font-size: 16px;
    flex-grow: 1;
}

.error-icon {
    color: #dc2626;
}

.warning-icon {
    color: #d97706;
}

.dismiss-btn {
    background: none;
    border: none;
    font-size: 20px;
    color: #9ca3af;
    cursor: pointer;
    padding: 0;
}

.error-details {
    margin-top: 16px;
    padding: 16px;
    background-color: #f8fafc;
    border-radius: 8px;
}

.error-list {
    max-height: 300px;
    overflow-y: auto;
    margin-bottom: 16px;
}

.error-item {
    display: flex;
    padding: 8px;
    background-color: white;
    border-radius: 4px;
    margin-bottom: 8px;
    font-size: 14px;
}

.error-row {
    font-weight: 600;
    color: #dc2626;
    margin-right: 8px;
    min-width: 60px;
}

.error-text {
    color: #374151;
    flex-grow: 1;
}

.retry-button {
    background-color: #3b82f6;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
}

.retry-button:hover {
    background-color: #2563eb;
}
</style>
```

### Accessibility Considerations

Ensure your error handling is accessible:

```javascript
// Announce errors to screen readers
const announceError = (message) => {
    const announcement = document.createElement('div');
    announcement.setAttribute('role', 'alert');
    announcement.setAttribute('aria-live', 'polite');
    announcement.className = 'sr-only';
    announcement.textContent = message;
    document.body.appendChild(announcement);
    
    setTimeout(() => {
        document.body.removeChild(announcement);
    }, 1000);
};

// Use in error handlers
const handleUploadError = (error) => {
    errorMessage.value = 'Upload failed. Please try again.';
    announceError(errorMessage.value);
};
```

### Best Practices Summary

1. **Validate early**: Check files on the client side before uploading
2. **Provide clear feedback**: Show specific error messages and actionable suggestions
3. **Handle network issues gracefully**: Implement timeouts and retry logic
4. **Be accessible**: Use ARIA attributes and screen reader announcements
5. **Log errors**: Send error details to your monitoring service
6. **Offer recovery options**: Provide retry buttons and alternative actions
7. **Don't overwhelm users**: Show only relevant error details and suggestions