import createClient, { type Middleware } from "openapi-fetch";
import type { paths } from "./schema.js";

export type { paths };
export type ApiClient = ReturnType<typeof createClient<paths>>;

export interface ClientOptions {
    baseUrl: string;
    accessToken?: string;
    fetch?: typeof globalThis.fetch;
}

export function createImpexClient(options: ClientOptions): ApiClient {
    const client = createClient<paths>({
        baseUrl: options.baseUrl,
        fetch: options.fetch,
    });

    if (options.accessToken) {
        const authMiddleware: Middleware = {
            async onRequest({ request }) {
                request.headers.set("Authorization", `Bearer ${options.accessToken}`);
                return request;
            },
        };
        client.use(authMiddleware);
    }

    return client;
}
