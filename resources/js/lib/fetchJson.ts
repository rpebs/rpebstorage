function csrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * JSON fetch helper for the few non-Inertia endpoints (Telegram OTP wizard),
 * with the Laravel CSRF header attached.
 */
export async function postJson<T = unknown>(
    url: string,
    data: Record<string, unknown>,
): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(data),
    });

    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw Object.assign(new Error(payload.message ?? 'Permintaan gagal'), {
            payload,
            status: response.status,
        });
    }

    return payload as T;
}
