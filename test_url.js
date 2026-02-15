
const url = "https://script.google.com/macros/s/AKfycbxl1_s90LIXNtV5ScepS8N74Ew9qjk_9xy583ePv5WkgWV09yO6_5Sa_1Ui6jTTN5WbuA/exec?action=version";

async function test() {
    try {
        console.log("Testing URL:", url);
        const response = await fetch(url, { redirect: 'follow' });
        console.log("Status:", response.status);
        console.log("Status Text:", response.statusText);
        const text = await response.text();
        console.log("Response Body:", text);
    } catch (error) {
        console.error("Error:", error.message);
    }
}

test();
