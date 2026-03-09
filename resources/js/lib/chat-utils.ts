import type { GatewayMessage, SessionMeta, SessionSummary, ToolCallContent } from '@/types/chat';

export function deriveFriendlyIdFromKey(key: string | undefined): string {
    if (!key) return 'main';
    const trimmed = key.trim();
    if (trimmed.length === 0) return 'main';
    const parts = trimmed.split(':');
    const tail = parts[parts.length - 1] ?? '';
    const tailTrimmed = tail.trim();
    return tailTrimmed.length > 0 ? tailTrimmed : trimmed;
}

export function textFromMessage(msg: GatewayMessage): string {
    const parts = Array.isArray(msg.content) ? msg.content : [];
    return parts
        .map((part) => (part.type === 'text' ? String(part.text ?? '') : ''))
        .join('')
        .trim();
}

export function getToolCallsFromMessage(msg: GatewayMessage): ToolCallContent[] {
    const parts = Array.isArray(msg.content) ? msg.content : [];
    return parts.filter(
        (part): part is ToolCallContent => part.type === 'toolCall',
    );
}

export function findToolResultForCall(
    toolCallId: string,
    messages: GatewayMessage[],
): GatewayMessage | undefined {
    return messages.find(
        (msg) => msg.role === 'toolResult' && msg.toolCallId === toolCallId,
    );
}

function normalizeTimestamp(value: unknown): number | null {
    if (typeof value === 'number' && Number.isFinite(value)) {
        if (value < 1_000_000_000_000) return value * 1000;
        return value;
    }
    if (typeof value === 'string') {
        const parsed = Date.parse(value);
        if (!Number.isNaN(parsed)) return parsed;
    }
    return null;
}

export function getMessageTimestamp(message: GatewayMessage): number {
    const candidates = [
        (message as Record<string, unknown>).createdAt,
        (message as Record<string, unknown>).created_at,
        (message as Record<string, unknown>).timestamp,
        (message as Record<string, unknown>).time,
        (message as Record<string, unknown>).ts,
    ];

    for (const candidate of candidates) {
        const normalized = normalizeTimestamp(candidate);
        if (normalized) return normalized;
    }

    return Date.now();
}

export function normalizeSessions(
    rows: SessionSummary[] | undefined,
): SessionMeta[] {
    if (!Array.isArray(rows)) return [];
    return rows.map((session) => {
        const key =
            typeof session.key === 'string' && session.key.trim().length > 0
                ? session.key.trim()
                : deriveFriendlyIdFromKey(session.friendlyId ?? session.key);
        const friendlyIdCandidate =
            typeof session.friendlyId === 'string' &&
            session.friendlyId.trim().length > 0
                ? session.friendlyId.trim()
                : deriveFriendlyIdFromKey(key);

        return {
            key,
            friendlyId: friendlyIdCandidate,
            title: typeof session.title === 'string' ? session.title : undefined,
            derivedTitle:
                typeof session.derivedTitle === 'string'
                    ? session.derivedTitle
                    : undefined,
            label: typeof session.label === 'string' ? session.label : undefined,
            updatedAt:
                typeof session.updatedAt === 'number' ? session.updatedAt : undefined,
            lastMessage: session.lastMessage ?? null,
            totalTokens:
                typeof session.totalTokens === 'number'
                    ? session.totalTokens
                    : undefined,
            contextTokens:
                typeof session.contextTokens === 'number'
                    ? session.contextTokens
                    : undefined,
        };
    });
}

export async function readError(res: Response): Promise<string> {
    try {
        const data = await res.json();
        if (data?.error) return String(data.error);
        if (data?.message) return String(data.message);
        return JSON.stringify(data);
    } catch {
        try {
            return await res.text();
        } catch {
            return res.statusText || 'Request failed';
        }
    }
}

export const missingGatewayAuthMessage =
    'Missing gateway auth. Set CLAWDBOT_GATEWAY_TOKEN (recommended) or CLAWDBOT_GATEWAY_PASSWORD in the server environment.';

export function isMissingGatewayAuth(message: string): boolean {
    return message.includes(missingGatewayAuthMessage);
}

export function isSessionNotFound(message: string): boolean {
    if (!message) return false;
    const normalized = message.toLowerCase();
    if (normalized.includes('session not found')) return true;
    if (normalized.includes('unknown session')) return true;
    if (normalized.includes('chat not found')) return true;
    if (normalized.includes('not found') && normalized.includes('session')) {
        return true;
    }
    return false;
}

// Format timestamp for display
export function formatTimestamp(timestamp: number): string {
    const date = new Date(timestamp);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;

    return date.toLocaleDateString();
}

// Create optimistic message for immediate display
export function createOptimisticMessage(
    body: string,
    attachments?: { file: File; base64?: string }[],
): { clientId: string; optimisticId: string; optimisticMessage: GatewayMessage } {
    const clientId = randomUUID();
    const optimisticId = `opt-${clientId}`;

    const content: GatewayMessage['content'] = [];

    // Add text content
    if (body.trim()) {
        content.push({ type: 'text', text: body });
    }

    // Add image attachments
    if (attachments) {
        for (const attachment of attachments) {
            if (attachment.base64 && attachment.file.type.startsWith('image/')) {
                content.push({
                    type: 'image',
                    source: {
                        type: 'base64',
                        media_type: attachment.file.type,
                        data: attachment.base64.split(',')[1] || attachment.base64,
                    },
                } as unknown as GatewayMessage['content'] extends (infer T)[] ? T : never);
            }
        }
    }

    const optimisticMessage: GatewayMessage = {
        id: optimisticId,
        role: 'user',
        content,
        timestamp: Date.now(),
        clientId,
        __optimisticId: optimisticId,
        status: 'sending',
    };

    return { clientId, optimisticId, optimisticMessage };
}

// Helper to generate UUID (already in utils, but duplicated here for convenience)
function randomUUID(): string {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
        return crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        const v = c === 'x' ? r : (r & 0x3) | 0x8;
        return v.toString(16);
    });
}
