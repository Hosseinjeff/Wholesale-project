
const DEPLOYMENT_URL = "https://script.google.com/macros/s/AKfycbzC9RKq6KbaZvp3ykjyphJoUkN5tEJp3tzOT3D23T97nLXpAA9t6z86r0O9_hCAUe0G/exec";

async function getJson(url, options = {}) {
    const res = await fetch(url, options);
    return res.json();
}

async function fetchWithRetry(url, options = {}, retries = 3, baseDelayMs = 800) {
    let attempt = 0;
    while (attempt <= retries) {
        try {
            const res = await fetch(url, options);
            const json = await res.json();
            return json;
        } catch (err) {
            if (attempt === retries) throw err;
            const delay = baseDelayMs * Math.pow(2, attempt) + Math.floor(Math.random() * 250);
            await new Promise(resolve => setTimeout(resolve, delay));
            attempt++;
        }
    }
}

async function processAllMessages() {
    console.log("Fetching all message data...");
    try {
        const status = await getJson(`${DEPLOYMENT_URL}?action=ingestion_status`);
        if (status && status.ingestion_enabled === false) {
            console.log("Ingestion paused. Resuming...");
            await getJson(`${DEPLOYMENT_URL}?action=resume_ingestion`);
        }
        const result = await getJson(`${DEPLOYMENT_URL}?action=get_message_data`);

        if (result.status !== 'success') {
            console.error("Failed to fetch messages:", result.message);
            return;
        }

        const messages = result.data;
        console.log(`Retrieved ${messages.length} messages. Processing...`);

        // Track processed content to avoid duplicates in this run
        const seenContent = new Set();
        let processedCount = 0;
        let skippedCount = 0;
        let successCount = 0;

        for (const msg of messages) {
            const contentKey = msg.content.trim();
            if (seenContent.has(contentKey)) {
                skippedCount++;
                continue;
            }
            seenContent.add(contentKey);

            console.log(`Processing message ID: ${msg.id} from ${msg.channel}...`);
            
            // Re-send to doPost for product extraction
            try {
                const postResult = await fetchWithRetry(DEPLOYMENT_URL, {
                    method: 'POST',
                    body: JSON.stringify({
                        id: msg.id + "_REPROCESS_" + Date.now(),
                        channel_username: msg.channel,
                        content: msg.content,
                        timestamp: msg.timestamp,
                        processing_mode: 'full'
                    }),
                    headers: { 'Content-Type': 'application/json' }
                }, 3, 800);
                
                if (postResult.status === 'success') {
                    console.log(`  Successfully processed: ${postResult.products_found} products found.`);
                    successCount++;
                } else {
                    console.log(`  Issue processing: ${postResult.status} - ${postResult.message}`);
                }
            } catch (err) {
                console.error(`  Error processing message ${msg.id}:`, err.message);
            }

            processedCount++;
            await new Promise(resolve => setTimeout(resolve, 700));
        }

        console.log("\n--- Processing Summary ---");
        console.log(`Total messages retrieved: ${messages.length}`);
        console.log(`Unique messages processed: ${processedCount}`);
        console.log(`Duplicates skipped: ${skippedCount}`);
        console.log(`Successful extractions: ${successCount}`);
        
    } catch (error) {
        console.error("Critical error:", error.message);
    }
}

processAllMessages();
