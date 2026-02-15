
const DEPLOYMENT_URL = "https://script.google.com/macros/s/AKfycbzC9RKq6KbaZvp3ykjyphJoUkN5tEJp3tzOT3D23T97nLXpAA9t6z86r0O9_hCAUe0G/exec";

async function compareExtraction() {
    try {
        // 1. Fetch products
        console.log("Fetching products...");
        const prodResponse = await fetch(`${DEPLOYMENT_URL}?action=get_products`);
        const prodResult = await prodResponse.json();
        const products = prodResult.data || [];

        // 2. Fetch messages
        console.log("Fetching messages...");
        const msgResponse = await fetch(`${DEPLOYMENT_URL}?action=get_message_data`);
        const msgResult = await msgResponse.json();
        const messages = msgResult.data || [];

        console.log(`\nComparison Report: ${messages.length} messages -> ${products.length} products\n`);

        // Group products by channel
        const productsByChannel = {};
        products.forEach(p => {
            if (!productsByChannel[p.channel]) productsByChannel[p.channel] = [];
            productsByChannel[p.channel].push(p);
        });

        // Show a few examples for each channel
        for (const channel in productsByChannel) {
            console.log(`--- Channel: ${channel} ---`);
            const channelProds = productsByChannel[channel].slice(-3); // Last 3 products
            channelProds.forEach(p => {
                console.log(`Product: ${p.name}`);
                console.log(`  Price: ${p.price} IRT`);
                console.log(`  Consumer: ${p.consumer_price} IRT`);
                console.log(`  Packaging: ${p.packaging}`);
                console.log("");
            });
        }

        // Find a message that produced multiple products
        const multiProductChannels = ['@top_shop_rahimi'];
        for (const channel of multiProductChannels) {
            const channelMsgs = messages.filter(m => m.channel === channel).slice(-2);
            for (const msg of channelMsgs) {
                const msgProds = products.filter(p => p.timestamp === msg.timestamp);
                console.log(`--- Multi-product Example (${channel}) ---`);
                console.log(`Message Content Snippet: ${msg.content.substring(0, 100).replace(/\n/g, ' ')}...`);
                console.log(`Products extracted: ${msgProds.length}`);
                msgProds.forEach(p => console.log(` - ${p.name}: ${p.price}`));
                console.log("");
            }
        }

    } catch (error) {
        console.error("Error:", error.message);
    }
}

compareExtraction();
