# @jayi/impex-sdk

A type-safe client for the Impex workflow API, generated from its OpenAPI spec.

```ts
import { createImpexClient } from '@jayi/impex-sdk'

const impex = createImpexClient({ baseUrl: 'https://example.test' })

const { data } = await impex.GET('/impex/runs', {
    params: { query: { status: 'failed' } },
})
```

Regenerate after changing the API:

```bash
npm run sdk:build
```

The Impex dashboard does not consume this SDK — it renders server-side through
Atrium. The SDK exists for programmatic consumers of the JSON API.
