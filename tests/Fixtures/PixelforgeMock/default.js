const http = require('node:http');
const { randomUUID } = require('node:crypto');
const pricing = {
    object: 'pricing',
    currency: 'usd',
    unit: 'per_1m_tokens',
    rates: {
        input_text_tokens: '5.00',
        input_image_tokens: '10.00',
        output_tokens: '40.00',
        reasoning_tokens: '12.50',
    },
    tokens: {
        characters_per_text_token: 4,
        text_token_variance: 0.15,
        per_reference_image: 765,
        output: {
            low: {
                '1024x1024': 280,
                '1536x1024': 420,
                '1024x1536': 420,
            },
            medium: {
                '1024x1024': 1100,
                '1536x1024': 1650,
                '1024x1536': 1650,
            },
            high: {
                '1024x1024': 4300,
                '1536x1024': 6450,
                '1024x1536': 6450,
            },
        },
        reasoning: {
            low: {
                min: 0,
                max: 500,
            },
            medium: {
                min: 200,
                max: 2000,
            },
            high: {
                min: 1000,
                max: 8000,
            },
        },
    },
};
const server = http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://localhost');
    const reply = (status, value, headers = {}) => {
        res.writeHead(status, {
            'Content-Type': 'application/json',
            ...headers,
        });
        res.end(JSON.stringify(value));
    };
    const error = (status, code, seconds = 0) =>
        reply(
            status,
            { error: { code, message: code } },
            seconds ? { 'Retry-After': String(seconds) } : {},
        );
    if (url.pathname === '/v1/pricing') return reply(200, pricing);
    if (req.headers.authorization !== 'Bearer simulation-key')
        return error(401, 'invalid_api_key');
    if (req.method === 'POST' && url.pathname === '/__reset') {
        await pm.state.set('pixelforge.records', []);
        return reply(200, { reset: true });
    }
    const records = (await pm.state.get('pixelforge.records')) || [];
    const finish = (record) => {
        if (Date.now() - record.acceptedAt < 1500) return record.object;
        const object = record.object;
        if (['succeeded', 'failed'].includes(object.status)) return object;
        object.started_at = object.created_at;
        object.completed_at = new Date().toISOString();
        if (object.prompt.includes('[fail]')) {
            object.status = 'failed';
            object.error = {
                code: 'generation_failed',
                message: 'Generation failed.',
            };
            return object;
        }
        object.status = 'succeeded';
        const base = Math.ceil(Array.from(object.prompt).length / 4);
        object.usage = {
            input_text_tokens: base,
            input_image_tokens: object.reference_images.length * 765,
            output_tokens: pricing.tokens.output[object.quality][object.size],
            reasoning_tokens: pricing.tokens.reasoning[object.quality].min,
        };
        object.usage.total_tokens = Object.values(object.usage).reduce(
            (sum, count) => sum + count,
            0,
        );
        object.image_url = 'https://example.com/simulated-output.png';
        object.image_url_expires_at = new Date(
            Date.now() + 86400000,
        ).toISOString();
        return object;
    };
    if (req.method === 'POST' && url.pathname === '/v1/generations') {
        const body = req.body;
        if (
            !body ||
            Object.keys(body).some(
                (key) =>
                    ![
                        'prompt',
                        'size',
                        'quality',
                        'reference_images',
                        'metadata',
                    ].includes(key),
            ) ||
            typeof body.prompt !== 'string' ||
            !pricing.tokens.output[body.quality]?.[body.size]
        )
            return error(422, 'validation_failed');
        const key = req.headers['idempotency-key'];
        const existing = records.find((record) => record.key === key);
        if (existing) {
            if (JSON.stringify(existing.input) !== JSON.stringify(body))
                return error(409, 'idempotency_key_reused');
            const object = finish(existing);
            await pm.state.set('pixelforge.records', records);
            return reply(202, object, { 'Idempotent-Replayed': 'true' });
        }
        if (body.prompt.includes('[blocked]'))
            return error(400, 'content_policy_violation');
        if (body.prompt.includes('[overloaded]'))
            return error(503, 'overloaded', 2);
        if (
            records.filter(
                (record) =>
                    !['succeeded', 'failed'].includes(finish(record).status),
            ).length >= 3
        )
            return error(429, 'concurrency_limit_exceeded', 5);
        const record = {
            key,
            input: body,
            acceptedAt: Date.now(),
            object: {
                id: `gen_${randomUUID()}`,
                object: 'generation',
                ...body,
                status: 'queued',
                image_url: null,
                image_url_expires_at: null,
                usage: null,
                rates: pricing.rates,
                error: null,
                created_at: new Date().toISOString(),
                started_at: null,
                completed_at: null,
            },
        };
        records.unshift(record);
        await pm.state.set('pixelforge.records', records);
        if (body.prompt.includes('[timeout]')) {
            setTimeout(() => reply(202, record.object), 11000);
            return;
        }
        return reply(202, record.object);
    }
    if (req.method === 'GET' && url.pathname === '/v1/generations') {
        const data = records.map(finish);
        await pm.state.set('pixelforge.records', records);
        return reply(200, {
            object: 'list',
            data,
            has_more: false,
            next_cursor: null,
        });
    }
    if (req.method === 'GET' && url.pathname.startsWith('/v1/generations/')) {
        const record = records.find(
            (item) => item.object.id === url.pathname.split('/').pop(),
        );
        if (!record) return error(404, 'not_found');
        const object = finish(record);
        await pm.state.set('pixelforge.records', records);
        return reply(200, object);
    }
    if (req.method === 'GET' && url.pathname === '/v1/usage') {
        const days = [];
        for (
            let day = new Date(
                `${url.searchParams.get('start_date')}T00:00:00Z`,
            );
            day.toISOString().slice(0, 10) <= url.searchParams.get('end_date');
            day.setUTCDate(day.getUTCDate() + 1)
        ) {
            const date = day.toISOString().slice(0, 10);
            const values = records
                .map(finish)
                .filter(
                    (item) =>
                        item.status === 'succeeded' &&
                        item.completed_at.startsWith(date),
                );
            const tokens = {
                input_text_tokens: 0,
                input_image_tokens: 0,
                output_tokens: 0,
                reasoning_tokens: 0,
            };
            for (const object of values)
                for (const type of Object.keys(tokens))
                    tokens[type] += object.usage[type];
            let nano = 0n;
            for (const type of Object.keys(tokens))
                nano +=
                    BigInt(tokens[type]) *
                    BigInt(pricing.rates[type].replace('.', '')) *
                    10n;
            days.push({
                date,
                generations: values.length,
                ...tokens,
                cost_usd: `${nano / 1000000000n}.${(nano % 1000000000n).toString().padStart(9, '0')}`,
            });
        }
        return reply(200, { object: 'usage', data: days });
    }
    return error(501, 'endpoint_not_modeled');
});
server.listen(process.env.PORT || 4500);
