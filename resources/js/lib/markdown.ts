import { marked } from 'marked';

// Configure marked for safe rendering
marked.setOptions({
    breaks: true, // Convert line breaks to <br>
    gfm: true, // GitHub Flavored Markdown
});

/**
 * Parse markdown text to HTML string
 */
export function parseMarkdown(text: string): string {
    if (!text || typeof text !== 'string') return '';
    
    try {
        return marked.parse(text) as string;
    } catch (error) {
        console.error('Markdown parsing error:', error);
        return text; // Return original text if parsing fails
    }
}

/**
 * Sanitize HTML to prevent XSS attacks
 * This is a basic sanitizer - for production, consider using DOMPurify
 */
export function sanitizeHtml(html: string): string {
    // Remove script tags
    html = html.replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '');
    
    // Remove event handlers
    html = html.replace(/on\w+="[^"]*"/gi, '');
    html = html.replace(/on\w+='[^']*'/gi, '');
    html = html.replace(/on\w+=[^\s>]*/gi, '');
    
    // Remove javascript: URLs
    html = html.replace(/javascript:[^"']*/gi, '');
    
    return html;
}

/**
 * Parse markdown and return sanitized HTML
 */
export function renderMarkdown(text: string): string {
    const html = parseMarkdown(text);
    return sanitizeHtml(html);
}
