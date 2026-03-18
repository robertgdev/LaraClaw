// Chat message types

export type ToolCallContent = {
    type: 'toolCall';
    id?: string;
    name?: string;
    arguments?: Record<string, unknown>;
    partialJson?: string;
};

export type ToolResultContent = {
    type: 'toolResult';
    toolCallId?: string;
    toolName?: string;
    content?: Array<{ type?: string; text?: string }>;
    details?: Record<string, unknown>;
    isError?: boolean;
};

export type TextContent = {
    type: 'text';
    text?: string;
    textSignature?: string;
};

export type ThinkingContent = {
    type: 'thinking';
    thinking?: string;
    thinkingSignature?: string;
};

export type MessageContent = TextContent | ToolCallContent | ThinkingContent;

// Feedback type: -1 = negative, 0 = neutral, 1 = positive
export type FeedbackValue = -1 | 0 | 1;

export type GatewayMessage = {
    role?: string;
    content?: MessageContent[];
    toolCallId?: string;
    toolName?: string;
    details?: Record<string, unknown>;
    isError?: boolean;
    timestamp?: number;
    [key: string]: unknown;
    __optimisticId?: string;
    __streamRunId?: string;
    id?: string;
    clientId?: string;
    status?: 'sending' | 'error' | 'sent';
    messageId?: string;
    feedback?: FeedbackValue;
    feedbackComment?: string;
};

export type SessionSummary = {
    key?: string;
    label?: string;
    title?: string;
    derivedTitle?: string;
    updatedAt?: number;
    lastMessage?: GatewayMessage | null;
    friendlyId?: string;
    totalTokens?: number;
    contextTokens?: number;
    feedback?: FeedbackValue;
    feedbackComment?: string;
};

export type SessionListResponse = {
    sessions?: SessionSummary[];
};

export type HistoryResponse = {
    sessionKey: string;
    sessionId?: string;
    messages: GatewayMessage[];
};

export type SessionMeta = {
    key: string;
    friendlyId: string;
    title?: string;
    derivedTitle?: string;
    label?: string;
    updatedAt?: number;
    lastMessage?: GatewayMessage | null;
    totalTokens?: number;
    contextTokens?: number;
    feedback?: FeedbackValue;
    feedbackComment?: string;
};

export type PathsPayload = {
    agentId: string;
    stateDir: string;
    sessionsDir: string;
    storePath: string;
};

// Tool part type for rendering
export type ToolPart = {
    type: string;
    state: 'input-streaming' | 'input-available' | 'output-available' | 'output-error';
    input?: Record<string, unknown>;
    output?: Record<string, unknown>;
    toolCallId?: string;
    errorText?: string;
};

// Attachment types
export type AttachmentFile = {
    id: string;
    file: File;
    base64?: string;
    preview?: string;
    error?: string;
};

// Chat settings types
export type ThemeMode = 'system' | 'light' | 'dark';
export type ThinkingLevel = 'low' | 'medium' | 'high';

export type ChatSettings = {
    showToolMessages: boolean;
    showReasoningBlocks: boolean;
    thinkingLevel: ThinkingLevel;
    theme: ThemeMode;
};

// Chat UI state
export type ChatUiState = {
    isSidebarCollapsed: boolean;
};

// Gateway status
export type GatewayStatusResponse = {
    ok: boolean;
    error?: string;
};

// Feedback message types for WebSocket
export type MessageFeedbackPayload = {
    type: 'feedback_message';
    message_id: string;
    feedback: FeedbackValue;
    comment?: string;
};

export type ConversationFeedbackPayload = {
    type: 'feedback_conversation';
    conversation_id: string;
    feedback: FeedbackValue;
    comment?: string;
};

export type FeedbackResponse = {
    type: 'feedback_message_saved' | 'feedback_conversation_saved';
    message: string;
    data: {
        success: boolean;
        message_id?: string;
        conversation_id?: string;
        feedback: FeedbackValue;
        feedback_label: string;
    };
};
