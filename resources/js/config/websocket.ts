/**
 * WebSocket configuration for LaraClaw server connection
 */

// Default WebSocket server port (must match LARACLAW_SERVER_PORT in .env)
const DEFAULT_PORT = 19123;

// Get port from meta tag or environment
function getWebSocketPort(): number {
    // Check for meta tag with port (set by backend)
    const metaPort = document.querySelector('meta[name="laraclaw-ws-port"]')?.getAttribute('content');
    if (metaPort) {
        const port = parseInt(metaPort, 10);
        if (!isNaN(port) && port > 0 && port <= 65535) {
            return port;
        }
    }
    
    // Check for global config
    if (typeof window !== 'undefined' && (window as any).__LARACLAW_WS_PORT__) {
        return (window as any).__LARACLAW_WS_PORT__;
    }
    
    return DEFAULT_PORT;
}

// Get WebSocket host
function getWebSocketHost(): string {
    // Check for meta tag with host
    const metaHost = document.querySelector('meta[name="laraclaw-ws-host"]')?.getAttribute('content');
    if (metaHost) {
        return metaHost;
    }
    
    // Default to same host as the page
    return window.location.hostname || 'localhost';
}

// Build WebSocket URL
export function getWebSocketUrl(): string {
    const host = getWebSocketHost();
    const port = getWebSocketPort();
    const protocol = window.location.protocol === 'https:' ? 'wss' : 'ws';
    return `${protocol}://${host}:${port}`;
}

// Export port for display purposes
export const WEBSOCKET_PORT = getWebSocketPort();

// WebSocket configuration object
export const websocketConfig = {
    port: getWebSocketPort(),
    host: getWebSocketHost(),
    url: getWebSocketUrl(),
    
    // Reconnection settings
    reconnect: true,
    reconnectInterval: 1000,
    maxReconnectInterval: 30000,
    reconnectDecay: 1.5,
    
    // Heartbeat settings
    heartbeatInterval: 30000,
    heartbeatTimeout: 5000,
};
