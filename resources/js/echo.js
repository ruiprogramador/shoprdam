import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;
Pusher.logToConsole = true; // ← adiciona para debug

console.log("App Key:", import.meta.env.VITE_REVERB_APP_KEY);
console.log("Host:", import.meta.env.VITE_REVERB_HOST);
console.log("Port:", import.meta.env.VITE_REVERB_PORT);
/* 
window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: 'shoprdam.test',
    wsPort: 8080,
    wssPort: 8080,
    forceTLS: true,
    enabledTransports: ['wss'],
}) */

/* window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: 'shoprdam.test',
    wsPort: 8080,
    wssPort: 8080,
    forceTLS: true,
    enableStats: true,
    enabledTransports: ['wss'],
    //cluster: 'mt1',
})  */

/* const checkConnection = () => {
    console.log('Socket:', window.Echo.connector.pusher.connection.socket)
    console.log('Strategy:', window.Echo.connector.pusher.connection.strategy)

    const transports = window.Echo.connector.pusher.connection.strategy.transports
    console.log('Transports:', transports)
    console.log('wss isSupported:', transports.wss.transport.isSupported())
    console.log('ws isSupported:', transports.ws.isSupported())

    const ws = new WebSocket('wss://shoprdam.test:8080/app/nia9g1bxnf7n9a9m1cek?protocol=7&client=js&version=8.5.0&flash=false')

    ws.onopen = () => console.log('Connected!')
    ws.onerror = (e) => console.log('Error:', e)
    ws.onclose = (e) => console.log('Closed:', e.code, e.reason)
    window.Echo.connector.pusher.connect()
} */

/* const initEcho = () => {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: 8080,
        wssPort: 8080,
        forceTLS: true,
        encrypted: true,
        enabledTransports: ['wss'],
        cluster: 'mt1',
        enableStats: false,
    })

    console.log("Echo initialized:", window.Echo)

    checkConnection()
} */

/* Este funciona para http : */ window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: 'shoprdam.test',
    //wsPort: 8080,
    forceTLS: false,
    enabledTransports: ['ws'],
}); 

/* window.Echo.connector.pusher.connection.bind('error', (err) => {
    console.error('Pusher error:', err)
})

window.Echo.connector.pusher.connection.bind('state_change', (states) => {
    console.log('State change:', states)
}) */

// Espera o DOM estar pronto para garantir que o Echo seja inicializado

/* if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initEcho)
} else {
    initEcho()
} */