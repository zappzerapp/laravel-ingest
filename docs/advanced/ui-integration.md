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