<script setup lang="ts">
import {
    Head,
    Link,
    router,
    useForm,
    useHttp,
    usePage,
    usePoll,
} from '@inertiajs/vue3';
import {
    ArrowDownLeft,
    ArrowUpRight,
    Check,
    CircleAlert,
    Clock3,
    ImagePlus,
    LoaderCircle,
    Plus,
    RefreshCw,
    Sparkles,
    WandSparkles,
    X,
} from '@lucide/vue';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import Button from '@/components/ui/button/Button.vue';
import Input from '@/components/ui/input/Input.vue';
import Label from '@/components/ui/label/Label.vue';
import { money } from '@/lib/money';
import { studio } from '@/routes';
import { auth as authorizeChannel } from '@/routes/broadcasting';
import { purchase } from '@/routes/credits';
import { estimate, store } from '@/routes/generations';
import type {
    Generation,
    GenerationPage,
    ReverbConfig,
    Wallet,
} from '@/types/generation';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Image studio', href: studio() }] },
});
const props = defineProps<{
    generations: GenerationPage;
    wallet: Wallet;
    providerConfigured: boolean;
    reverb: ReverbConfig;
}>();
const page = usePage();
const generationForm = ref(
    useForm({
        request_id: crypto.randomUUID(),
        type: 'image',
        prompt: '',
        settings: {
            size: '1024x1024',
            quality: 'medium',
            reference_images: [] as string[],
        },
    }),
);
const purchaseForm = useForm({ request_id: crypto.randomUUID() });
const quote = useHttp<
    {
        type: string;
        prompt: string;
        settings: { size: string; quality: string; reference_images: string[] };
    },
    { data: { minimum: string; maximum: string } }
>({
    type: 'image',
    prompt: '',
    settings: { size: '1024x1024', quality: 'medium', reference_images: [] },
});
const quoteValue = ref<{ minimum: string; maximum: string } | null>(null);
const quoteError = ref('');
const quoteLoading = ref(false);
const selectedId = ref<string | null>(null);
const connection = ref<'connecting' | 'live' | 'refresh'>('connecting');
const brokenImages = ref(new Set<string>());
const referenceInput = ref('');
const referenceError = ref('');
const active = computed(
    () =>
        props.generations.data.some((generation) =>
            ['queued', 'processing'].includes(generation.status),
        ) || BigInt(props.wallet.reserved) > 0n,
);
const selected = computed(
    () =>
        props.generations.data.find(
            (generation) => generation.id === selectedId.value,
        ) ?? props.generations.data[0],
);
const canGenerate = computed(
    () =>
        props.providerConfigured &&
        generationForm.value.prompt.trim().length > 0 &&
        Array.from(generationForm.value.prompt).length <= 4000 &&
        quoteValue.value !== null &&
        !quoteLoading.value &&
        !generationForm.value.processing &&
        !props.wallet.deficit &&
        BigInt(props.wallet.available) >= BigInt(quoteValue.value.maximum),
);
const { start, stop } = usePoll(
    15000,
    { only: ['generations', 'wallet'] },
    { autoStart: false },
);
watch(active, (running) => (running ? start() : stop()), { immediate: true });
watch(
    () => props.generations.data,
    () => {
        const requestedId = new URL(
            page.url,
            window.location.origin,
        ).searchParams.get('generation');
        if (
            requestedId &&
            props.generations.data.some(
                (generation) => generation.id === requestedId,
            )
        )
            selectedId.value = requestedId;
    },
    { immediate: true },
);
let timer: ReturnType<typeof setTimeout> | undefined;
let quoteVersion = 0;
watch(
    () =>
        JSON.stringify({
            prompt: generationForm.value.prompt,
            settings: generationForm.value.settings,
        }),
    () => {
        clearTimeout(timer);
        generationForm.value.request_id = crypto.randomUUID();
        quoteVersion++;
        quoteValue.value = null;
        quoteError.value = '';
        quote.cancel();
        if (!generationForm.value.prompt.trim() || !props.providerConfigured) {
            quoteLoading.value = false;
            return;
        }
        quoteLoading.value = true;
        const version = quoteVersion;
        timer = setTimeout(() => {
            quote.prompt = generationForm.value.prompt;
            quote.settings = structuredClone({
                size: generationForm.value.settings.size,
                quality: generationForm.value.settings.quality,
                reference_images: [
                    ...generationForm.value.settings.reference_images,
                ],
            });
            quote
                .post(estimate.url())
                .then((response) => {
                    if (version === quoteVersion)
                        quoteValue.value = response.data;
                })
                .catch(() => {
                    if (version === quoteVersion)
                        quoteError.value =
                            Object.values(quote.errors).flat().join(' ') ||
                            'Could not load pricing. Edit the prompt to retry.';
                })
                .finally(() => {
                    if (version === quoteVersion) quoteLoading.value = false;
                });
        }, 450);
    },
);
function generate(): void {
    if (!canGenerate.value) return;
    generationForm.value.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            generationForm.value.request_id = crypto.randomUUID();
        },
    });
}
function buyCredits(): void {
    purchaseForm.post(purchase.url(), {
        preserveScroll: true,
        onSuccess: () => {
            purchaseForm.request_id = crypto.randomUUID();
        },
    });
}
function addReference(): void {
    referenceError.value = '';
    try {
        const url = new URL(referenceInput.value);
        if (
            !['http:', 'https:'].includes(url.protocol) ||
            generationForm.value.settings.reference_images.length >= 4
        )
            throw new Error();
        generationForm.value.settings.reference_images.push(
            referenceInput.value.trim(),
        );
        referenceInput.value = '';
    } catch {
        referenceError.value =
            'Enter an HTTP or HTTPS image URL. You can add up to four.';
    }
}
function reuse(generation: Generation): void {
    generationForm.value.request_id = crypto.randomUUID();
    generationForm.value.prompt = generation.prompt;
    generationForm.value.settings = {
        ...generation.settings,
        reference_images: [...generation.settings.reference_images],
    };
}
function refresh(): void {
    router.reload({ only: ['generations', 'wallet'] });
}
let echo: Echo<'pusher'> | undefined;
onMounted(() => {
    if (!props.reverb.key) {
        connection.value = 'refresh';
        return;
    }
    echo = new Echo({
        broadcaster: 'pusher',
        key: props.reverb.key,
        cluster: 'mt1',
        client: new Pusher(props.reverb.key, {
            cluster: 'mt1',
            wsHost: props.reverb.host,
            wsPort: props.reverb.port,
            wssPort: props.reverb.port,
            forceTLS: props.reverb.scheme === 'https',
            enabledTransports: ['ws', 'wss'],
            channelAuthorization: {
                endpoint: authorizeChannel.url(),
                transport: 'ajax',
                headers: {
                    'X-XSRF-TOKEN': decodeURIComponent(
                        document.cookie
                            .split('; ')
                            .find((item) => item.startsWith('XSRF-TOKEN='))
                            ?.split('=')
                            .slice(1)
                            .join('=') ?? '',
                    ),
                },
            },
        }),
    });
    echo.private(`users.${page.props.auth.user.id}`).listen(
        'GenerationUpdated',
        refresh,
    );
    const client = echo.connector.pusher;
    client.connection.bind('connected', () => {
        connection.value = 'live';
        refresh();
    });
    client.connection.bind('unavailable', () => {
        connection.value = 'refresh';
    });
    client.connection.bind('disconnected', () => {
        connection.value = 'refresh';
    });
});
onBeforeUnmount(() => {
    clearTimeout(timer);
    quote.cancel();
    echo?.disconnect();
    stop();
});
</script>

<template>
    <Head title="Image studio" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-7 p-4 md:p-8">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p
                    class="mb-2 text-xs font-semibold tracking-[0.2em] text-muted-foreground uppercase"
                >
                    PromptEdit / Create
                </p>
                <h1 class="text-3xl font-semibold tracking-tight">
                    Your next idea, visualized.
                </h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    Start with a few words. See the cost before you create.
                </p>
            </div>
            <div
                class="flex items-center gap-2 rounded-full border px-3 py-2 text-xs text-muted-foreground"
            >
                <span
                    :class="[
                        'size-1.5 rounded-full',
                        connection === 'live'
                            ? 'bg-emerald-500'
                            : 'bg-amber-500',
                    ]"
                />{{
                    connection === 'live' ? 'Live updates' : 'Automatic refresh'
                }}
            </div>
        </header>
        <div
            v-if="!providerConfigured"
            class="flex gap-3 rounded-xl border border-amber-500/30 bg-amber-500/5 p-4 text-sm"
        >
            <CircleAlert class="size-5 shrink-0 text-amber-600" />
            <p>
                Connect Pixelforge to start generating. The operator needs to
                configure an API key. You can explore the studio and add demo
                credits now.
            </p>
        </div>
        <div
            v-if="wallet.deficit"
            class="rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive"
        >
            Your final provider charge exceeded its reservation. Add credits to
            cover the deficit before generating again.
        </div>
        <section class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Credit balance</p>
                <p
                    class="mt-1 font-mono text-xl"
                    :title="money(wallet.balance, 12)"
                >
                    {{ money(wallet.balance, 2) }}
                </p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    Reserved for ongoing work
                </p>
                <p
                    class="mt-1 font-mono text-xl"
                    :title="money(wallet.reserved, 12)"
                >
                    {{ money(wallet.reserved) }}
                </p>
            </div>
            <div
                class="flex items-center justify-between gap-3 rounded-xl border bg-card p-4"
            >
                <div>
                    <p class="text-xs text-muted-foreground">
                        Available to spend
                    </p>
                    <p
                        class="mt-1 font-mono text-xl"
                        :title="money(wallet.available, 12)"
                    >
                        {{ money(wallet.available) }}
                    </p>
                </div>
                <Button
                    variant="outline"
                    :disabled="purchaseForm.processing"
                    @click="buyCredits"
                    ><Plus class="size-4" />Buy $10</Button
                >
            </div>
        </section>
        <p class="-mt-5 text-right text-xs text-muted-foreground">
            Demo credits only. No real payments.
        </p>
        <div class="grid items-start gap-6 lg:grid-cols-[380px_minmax(0,1fr)]">
            <form
                class="rounded-2xl border bg-card p-5"
                @submit.prevent="generate"
            >
                <div class="mb-6 flex items-center gap-2">
                    <WandSparkles class="size-4" />
                    <h2 class="font-semibold">Create an image</h2>
                </div>
                <Label for="prompt">Your prompt</Label>
                <textarea
                    id="prompt"
                    v-model="generationForm.prompt"
                    class="mt-2 min-h-36 w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    placeholder="A quiet coastal town at blue hour, warm windows reflected in the water…"
                ></textarea>
                <div
                    class="mt-1 flex justify-between text-xs text-muted-foreground"
                >
                    <span>Be as specific as you like.</span
                    ><span
                        >{{
                            Array.from(generationForm.prompt).length
                        }}/4,000</span
                    >
                </div>
                <InputError :message="generationForm.errors.prompt" />
                <fieldset class="mt-6">
                    <legend class="mb-2 text-sm font-medium">Canvas</legend>
                    <div class="grid grid-cols-3 gap-2">
                        <button
                            v-for="size in [
                                {
                                    value: '1024x1024',
                                    label: 'Square',
                                    ratio: '1:1',
                                },
                                {
                                    value: '1536x1024',
                                    label: 'Landscape',
                                    ratio: '3:2',
                                },
                                {
                                    value: '1024x1536',
                                    label: 'Portrait',
                                    ratio: '2:3',
                                },
                            ]"
                            :key="size.value"
                            type="button"
                            :aria-pressed="
                                generationForm.settings.size === size.value
                            "
                            :class="[
                                'rounded-lg border p-3 text-left transition',
                                generationForm.settings.size === size.value
                                    ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                    : 'hover:bg-muted',
                            ]"
                            @click="generationForm.settings.size = size.value"
                        >
                            <span class="block text-xs font-medium">{{
                                size.label
                            }}</span
                            ><span
                                class="mt-1 block text-xs text-muted-foreground"
                                >{{ size.ratio }}</span
                            >
                        </button>
                    </div>
                    <InputError
                        :message="generationForm.errors['settings.size']"
                    />
                </fieldset>
                <fieldset class="mt-5">
                    <legend class="mb-2 text-sm font-medium">Quality</legend>
                    <div class="flex rounded-lg bg-muted p-1">
                        <button
                            v-for="quality in ['low', 'medium', 'high']"
                            :key="quality"
                            type="button"
                            :aria-pressed="
                                generationForm.settings.quality === quality
                            "
                            :class="[
                                'flex-1 rounded-md py-2 text-xs font-medium capitalize transition',
                                generationForm.settings.quality === quality
                                    ? 'bg-background shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground',
                            ]"
                            @click="generationForm.settings.quality = quality"
                        >
                            {{ quality }}
                        </button>
                    </div>
                </fieldset>
                <div class="mt-6">
                    <Label for="reference"
                        >Reference images
                        <span class="font-normal text-muted-foreground"
                            >(optional)</span
                        ></Label
                    >
                    <div class="mt-2 flex gap-2">
                        <Input
                            id="reference"
                            v-model="referenceInput"
                            type="url"
                            placeholder="https://example.com/image.png"
                            @keydown.enter.prevent="addReference"
                        /><Button
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label="Add reference URL"
                            :disabled="
                                generationForm.settings.reference_images
                                    .length >= 4
                            "
                            @click="addReference"
                            ><ImagePlus class="size-4"
                        /></Button>
                    </div>
                    <InputError :message="referenceError" />
                    <ul class="mt-2 space-y-2">
                        <li
                            v-for="(url, index) in generationForm.settings
                                .reference_images"
                            :key="index"
                            class="flex items-center gap-2 rounded-md bg-muted px-2 py-1 text-xs"
                        >
                            <span class="min-w-0 flex-1 truncate">{{
                                url
                            }}</span
                            ><button
                                type="button"
                                aria-label="Remove reference"
                                @click="
                                    generationForm.settings.reference_images.splice(
                                        index,
                                        1,
                                    )
                                "
                            >
                                <X class="size-3" />
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="mt-6 rounded-xl bg-muted/60 p-4">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium">Estimated charge</span
                        ><LoaderCircle
                            v-if="quoteLoading"
                            class="size-3.5 animate-spin"
                        /><span v-else-if="quoteValue" class="font-mono text-sm"
                            >{{ money(quoteValue.minimum) }}–{{
                                money(quoteValue.maximum)
                            }}</span
                        ><span v-else class="text-xs text-muted-foreground">{{
                            generationForm.prompt
                                ? 'Unavailable'
                                : 'Write a prompt'
                        }}</span>
                    </div>
                    <p
                        class="mt-2 text-xs leading-relaxed text-muted-foreground"
                    >
                        Includes 25% markup. Actual cost depends on tokens used.
                        We reserve the maximum estimate and release the
                        difference.
                    </p>
                    <p v-if="quoteError" class="mt-2 text-xs text-destructive">
                        {{ quoteError }}
                    </p>
                    <p
                        v-if="
                            quoteValue &&
                            BigInt(wallet.available) <
                                BigInt(quoteValue.maximum)
                        "
                        class="mt-2 text-xs text-destructive"
                    >
                        Add credits to cover this generation.
                    </p>
                </div>
                <div v-if="generationForm.hasErrors" class="mt-3 space-y-1">
                    <p
                        v-for="(error, key) in generationForm.errors"
                        :key="key"
                        class="text-xs text-destructive"
                    >
                        {{ error }}
                    </p>
                </div>
                <Button
                    type="submit"
                    class="mt-4 w-full"
                    :disabled="!canGenerate"
                    ><LoaderCircle
                        v-if="generationForm.processing"
                        class="size-4 animate-spin" /><Sparkles
                        v-else
                        class="size-4" />{{
                        generationForm.processing
                            ? 'Saving your generation…'
                            : 'Generate image'
                    }}<ArrowUpRight
                        v-if="!generationForm.processing"
                        class="ml-auto size-4"
                /></Button>
                <p class="mt-3 text-center text-xs text-muted-foreground">
                    Powered by Pixelforge · simulated image generation
                </p>
            </form>
            <section class="min-w-0 overflow-hidden rounded-2xl border bg-card">
                <div
                    class="flex items-center justify-between border-b px-5 py-4"
                >
                    <h2 class="text-sm font-semibold">
                        {{
                            selected
                                ? 'Generation preview'
                                : 'A little space for a big idea'
                        }}
                    </h2>
                    <span
                        v-if="selected"
                        class="rounded-full bg-muted px-2.5 py-1 text-xs capitalize"
                        >{{ selected.status }}</span
                    ><span v-else class="text-xs text-muted-foreground"
                        >Your canvas</span
                    >
                </div>
                <div
                    v-if="!selected"
                    class="flex min-h-[440px] flex-col items-center justify-center bg-[radial-gradient(ellipse_at_center,var(--color-muted),transparent_75%)] p-10 text-center"
                >
                    <div
                        class="mb-6 flex size-20 -rotate-6 items-center justify-center rounded-2xl border bg-background shadow-sm"
                    >
                        <ImagePlus class="size-8 text-muted-foreground" />
                    </div>
                    <h3 class="text-lg font-medium">
                        It starts with your imagination.
                    </h3>
                    <p
                        class="mt-2 max-w-xs text-sm leading-relaxed text-muted-foreground"
                    >
                        Describe a scene, choose your canvas, and bring an idea
                        into view.
                    </p>
                    <div class="mt-8 flex gap-2">
                        <button
                            v-for="example in [
                                'A lighthouse at dawn',
                                'An abstract botanical print',
                            ]"
                            :key="example"
                            class="rounded-full border bg-background px-3 py-2 text-xs hover:bg-muted"
                            @click="generationForm.prompt = example"
                        >
                            {{ example
                            }}<ArrowUpRight class="ml-1 inline size-3" />
                        </button>
                    </div>
                </div>
                <template v-else>
                    <div
                        v-if="selected.status === 'succeeded'"
                        class="grid min-h-[380px] gap-3 bg-muted/30 p-4"
                        :class="
                            selected.outputs.length > 1 ? 'sm:grid-cols-2' : ''
                        "
                    >
                        <div
                            v-for="output in selected.outputs"
                            :key="output.id"
                            class="flex min-h-[340px] items-center justify-center overflow-hidden rounded-lg"
                        >
                            <img
                                v-if="
                                    !output.expired &&
                                    !brokenImages.has(output.url)
                                "
                                :src="output.url"
                                :alt="selected.prompt"
                                class="max-h-[560px] max-w-full rounded-lg object-contain"
                                @error="brokenImages.add(output.url)"
                            />
                            <div
                                v-else
                                class="p-10 text-center text-sm text-muted-foreground"
                            >
                                <ImagePlus class="mx-auto mb-3 size-8" />This
                                image is no longer available.
                                <p class="mt-1 text-xs">
                                    Provider image links expire after 24 hours.
                                </p>
                            </div>
                        </div>
                    </div>
                    <div
                        v-else
                        class="flex min-h-[380px] flex-col items-center justify-center p-10 text-center"
                    >
                        <CircleAlert
                            v-if="selected.status === 'failed'"
                            class="mb-5 size-10 text-destructive"
                        /><LoaderCircle
                            v-else
                            class="mb-5 size-10 animate-spin text-muted-foreground"
                        />
                        <h3 class="text-lg font-medium">
                            {{
                                selected.status === 'failed'
                                    ? 'This one didn’t work out.'
                                    : selected.status === 'processing'
                                      ? 'Your idea is taking shape.'
                                      : 'Your idea is in the queue.'
                            }}
                        </h3>
                        <p class="mt-3 max-w-sm text-sm text-muted-foreground">
                            {{
                                selected.error_message ||
                                'You can keep creating. We’ll update this view as soon as your image is ready.'
                            }}
                        </p>
                        <Button
                            v-if="selected.status === 'failed'"
                            variant="outline"
                            class="mt-5"
                            @click="reuse(selected)"
                            ><RefreshCw class="size-4" />Use these settings
                            again</Button
                        >
                    </div>
                    <div class="border-t p-5">
                        <p class="text-sm leading-relaxed">
                            {{ selected.prompt }}
                        </p>
                        <div
                            class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-muted-foreground"
                        >
                            <span>{{ selected.settings.size }}</span
                            ><span class="capitalize"
                                >{{ selected.settings.quality }} quality</span
                            ><span
                                :title="
                                    selected.actual_charge !== null
                                        ? money(selected.actual_charge, 12)
                                        : ''
                                "
                                >{{
                                    selected.actual_charge === null
                                        ? 'Final charge pending'
                                        : `Charged ${money(selected.actual_charge)}`
                                }}</span
                            ><button
                                class="ml-auto inline-flex items-center gap-1 text-foreground hover:underline"
                                @click="reuse(selected)"
                            >
                                <ArrowDownLeft class="size-3" />Reuse prompt
                            </button>
                        </div>
                    </div>
                </template>
            </section>
        </div>
        <section class="mt-2">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h2 class="font-semibold">Your generations</h2>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ generations.meta.total }} ideas explored. Every cost
                        accounted for.
                    </p>
                </div>
                <Button variant="ghost" size="sm" @click="refresh"
                    ><RefreshCw class="size-3.5" />Refresh</Button
                >
            </div>
            <div
                v-if="!generations.data.length"
                class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                Your first generation will appear here.
            </div>
            <div v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <button
                    v-for="generation in generations.data"
                    :key="generation.id"
                    :class="[
                        'rounded-xl border bg-card p-4 text-left transition hover:border-foreground/30',
                        selected?.id === generation.id
                            ? 'ring-1 ring-foreground/40'
                            : '',
                    ]"
                    @click="selectedId = generation.id"
                >
                    <div
                        class="relative mb-4 flex aspect-[4/3] items-center justify-center overflow-hidden rounded-lg bg-muted/50"
                    >
                        <img
                            v-if="
                                generation.status === 'succeeded' &&
                                generation.outputs[0] &&
                                !generation.outputs[0].expired &&
                                !brokenImages.has(generation.outputs[0].url)
                            "
                            :src="generation.outputs[0].url"
                            :alt="generation.prompt"
                            loading="lazy"
                            decoding="async"
                            class="h-full w-full object-cover"
                            @error="brokenImages.add(generation.outputs[0].url)"
                        />
                        <div
                            v-else
                            class="flex flex-col items-center gap-2 p-4 text-center text-xs text-muted-foreground"
                        >
                            <CircleAlert
                                v-if="generation.status === 'failed'"
                                class="size-7 text-destructive"
                            />
                            <LoaderCircle
                                v-else-if="generation.status === 'processing'"
                                class="size-7 animate-spin"
                            />
                            <Clock3
                                v-else-if="generation.status === 'queued'"
                                class="size-7"
                            />
                            <ImagePlus v-else class="size-7" />
                            <span>{{
                                generation.status === 'failed'
                                    ? 'Generation failed'
                                    : generation.status === 'processing'
                                      ? 'Generating image…'
                                      : generation.status === 'queued'
                                        ? 'Waiting to generate'
                                        : generation.outputs[0]?.expired
                                          ? 'Image expired'
                                          : 'Image unavailable'
                            }}</span>
                        </div>
                        <span
                            v-if="generation.outputs.length > 1"
                            class="absolute right-2 bottom-2 rounded-md bg-black/70 px-2 py-1 text-xs text-white"
                            >{{ generation.outputs.length }} images</span
                        >
                    </div>
                    <div class="mb-3 flex items-center justify-between text-xs">
                        <span
                            class="inline-flex items-center gap-1.5 capitalize"
                            ><Check
                                v-if="generation.status === 'succeeded'"
                                class="size-3.5 text-emerald-600"
                            /><CircleAlert
                                v-else-if="generation.status === 'failed'"
                                class="size-3.5 text-destructive"
                            /><Clock3
                                v-else
                                class="size-3.5 text-amber-600"
                            />{{ generation.status }}</span
                        ><span class="text-muted-foreground">{{
                            new Date(generation.created_at).toLocaleTimeString(
                                [],
                                { hour: '2-digit', minute: '2-digit' },
                            )
                        }}</span>
                    </div>
                    <p class="line-clamp-2 min-h-10 text-sm">
                        {{ generation.prompt }}
                    </p>
                    <div
                        class="mt-4 space-y-1 border-t pt-3 text-xs text-muted-foreground"
                    >
                        <p class="flex justify-between">
                            <span>Estimated</span
                            ><span class="font-mono"
                                >{{ money(generation.estimated_min) }}–{{
                                    money(generation.estimated_max)
                                }}</span
                            >
                        </p>
                        <p class="flex justify-between">
                            <span>Actual</span
                            ><span class="font-mono text-foreground">{{
                                generation.actual_charge === null
                                    ? 'Pending'
                                    : money(generation.actual_charge)
                            }}</span>
                        </p>
                    </div>
                </button>
            </div>
            <nav
                v-if="generations.meta.last_page > 1"
                class="mt-5 flex justify-center gap-2"
                aria-label="Generation history pagination"
            >
                <template
                    v-for="(link, index) in generations.links"
                    :key="index"
                    ><Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-scroll
                        :class="[
                            'rounded-md border px-3 py-1.5 text-xs',
                            link.active
                                ? 'bg-primary text-primary-foreground'
                                : 'hover:bg-muted',
                        ]"
                        ><span>{{
                            link.label
                                .replace(/&laquo;/g, '‹')
                                .replace(/&raquo;/g, '›')
                        }}</span></Link
                    ></template
                >
            </nav>
        </section>
    </div>
</template>
