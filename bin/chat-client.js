var conn = new WebSocket('ws://localhost:8080');
conn.onopen = function(e) {
    console.log("Connection established!");
};

conn.onmessage = function(e) {
    console.log(e.data);
};

// once you see connection establshed, you can send messages like this: 
// conn.send('Hello World!');
