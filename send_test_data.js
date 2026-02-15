
const testCases = [
    {
        id: "test_" + Date.now() + "_1_" + Math.floor(Math.random() * 1000),
        channel_username: "@bonakdarjavan",
        content: "کنسرو ماهی ۱۸۰ گرمی تاپ\n✅\nقیمت هر باکس: ۱,۲۵۰,۰۰۰ تومان\nدونه ای: ۵۲,۰۰۰ تومان\nقیمت مصرف: ۶۵,۰۰۰ تومان\nتعداد در باکس: ۲۴ عددی\nموجود ✅",
        timestamp: new Date().toISOString()
    },
    {
        id: "test_" + Date.now() + "_2_" + Math.floor(Math.random() * 1000),
        channel_username: "@top_shop_rahimi",
        content: "انرژی زا هایپ اصلی\n✅در باکس ۲۴عددی\n✅قیمت هر باکس: ۱,۲۰۰,۰۰۰ تومان\n✅قیمت مصرف: ۶۵,۰۰۰ تومان",
        timestamp: new Date().toISOString()
    },
    {
        id: "test_" + Date.now() + "_3_" + Math.floor(Math.random() * 1000),
        channel_username: "@nobelshop118",
        content: "کاپوچینو گوددی ۳۰ تایی\n: ۷۵/۰۰۰\n\nهات چاکلت ۲۰ تایی\n: ۶۵/۰۰۰",
        timestamp: new Date().toISOString()
    }
];

const deployments = [
    "https://script.google.com/macros/s/AKfycbxl1_s90LIXNtV5ScepS8N74Ew9qjk_9xy583ePv5WkgWV09yO6_5Sa_1Ui6jTTN5WbuA/exec"
];

async function sendTestData() {
    for (const url of deployments) {
        const deploymentLabel = 'New Deployment';
        console.log(`\n--- Sending to ${deploymentLabel}: ${url} ---`);
        
        for (const testCase of testCases) {
            // Unique ID per deployment to avoid duplicate check if they share a spreadsheet
            const uniqueTestCase = {
                ...testCase,
                id: testCase.id + "_FINAL"
            };
            
            console.log(`Sending test case: ${uniqueTestCase.id} (${uniqueTestCase.channel_username})...`);
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    body: JSON.stringify(uniqueTestCase),
                    headers: { 'Content-Type': 'application/json' }
                });
                const result = await response.json();
                console.log(`Result: ${JSON.stringify(result, null, 2)}`);
            } catch (error) {
                console.error(`Error sending test case ${testCase.id}: ${error.message}`);
            }
        }
        
        console.log(`\nChecking version for deployment: ${url}`);
        try {
            const versionUrl = `${url}?action=version`;
            const response = await fetch(versionUrl);
            const result = await response.json();
            console.log(`Version Result: ${JSON.stringify(result, null, 2)}`);
        } catch (error) {
            console.error(`Error checking version: ${error.message}`);
        }

        console.log(`\nRunning internal test_extraction for deployment: ${url}`);
        try {
            const testUrl = `${url}?action=test_extraction`;
            const response = await fetch(testUrl);
            const result = await response.json();
            console.log(`Internal Test Result: ${JSON.stringify(result, null, 2)}`);
        } catch (error) {
            console.error(`Error running internal test: ${error.message}`);
        }

        console.log(`\nChecking ExecutionLogs for deployment: ${url}`);
        try {
            const logsUrl = `${url}?action=get_execution_logs`;
            const response = await fetch(logsUrl);
            const result = await response.json();
            console.log(`ExecutionLogs Result (last 3):`);
            if (result.status === 'success' && result.data) {
                console.log(JSON.stringify(result.data.slice(-3), null, 2));
            } else {
                console.log(`Failed to retrieve logs: ${JSON.stringify(result)}`);
            }
        } catch (error) {
            console.error(`Error checking logs: ${error.message}`);
        }

        console.log(`\nVerifying extracted products for deployment: ${url}`);
        try {
            const verifyUrl = `${url}?action=get_products`;
            const response = await fetch(verifyUrl);
            const result = await response.json();
            console.log(`Verification Result (last 5 products):`);
            if (result.status === 'success' && result.data) {
                console.log(JSON.stringify(result.data.slice(-5), null, 2));
            } else {
                console.log(`Failed to retrieve products: ${JSON.stringify(result)}`);
            }
        } catch (error) {
            console.error(`Error verifying results: ${error.message}`);
        }
    }
}

sendTestData();
