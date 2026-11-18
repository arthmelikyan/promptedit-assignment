<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowUpRight, RefreshCw, ShieldCheck } from '@lucide/vue';
import Button from '@/components/ui/button/Button.vue';
import Input from '@/components/ui/input/Input.vue';
import Label from '@/components/ui/label/Label.vue';
import { money } from '@/lib/money';
import { spend } from '@/routes/admin';

type SpendRow = {
    date?: string;
    user_id?: number;
    name?: string;
    email?: string;
    generations: number;
    provider_cost: string;
    user_charge: string;
    profit: string;
    reported_provider_cost?: string | null;
    difference?: string | null;
    reported_generations?: number | null;
};
type Report = {
    daily: SpendRow[];
    users: SpendRow[];
    totals: { provider_cost: string; user_charge: string; profit: string };
    provider_synced_at: string | null;
    provider_error: string | null;
    filters: { start_date: string; end_date: string; user_id: number | null };
};
const props = defineProps<{ report: Report }>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Spend report', href: spend() }] },
});
const filters = useForm({
    start_date: props.report.filters.start_date,
    end_date: props.report.filters.end_date,
});
function apply(): void {
    filters.get(spend.url(), { preserveState: true });
}
function refresh(): void {
    router.get(
        spend.url(),
        { ...filters.data(), refresh: 1 },
        { preserveState: true, preserveScroll: true },
    );
}
</script>
<template>
    <Head title="Spend report" />
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-8">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p
                    class="mb-2 flex items-center gap-2 text-xs font-semibold tracking-widest text-muted-foreground uppercase"
                >
                    <ShieldCheck class="size-3.5" />Operator workspace
                </p>
                <h1 class="text-3xl font-semibold tracking-tight">
                    Every generation, accounted for.
                </h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    Exact spending and margin, grouped by completion date in
                    UTC.
                </p>
            </div>
            <Button variant="outline" @click="refresh"
                ><RefreshCw class="size-4" />Reconcile provider</Button
            >
        </header>
        <form
            class="flex flex-wrap items-end gap-3 rounded-xl border bg-card p-4"
            @submit.prevent="apply"
        >
            <div>
                <Label for="start">From (UTC)</Label
                ><Input
                    id="start"
                    v-model="filters.start_date"
                    type="date"
                    class="mt-2"
                />
            </div>
            <div>
                <Label for="end">Through (UTC)</Label
                ><Input
                    id="end"
                    v-model="filters.end_date"
                    type="date"
                    class="mt-2"
                />
            </div>
            <Button :disabled="filters.processing"
                >Apply range<ArrowUpRight class="size-3.5"
            /></Button>
            <p
                v-for="error in filters.errors"
                :key="error"
                class="text-xs text-destructive"
            >
                {{ error }}
            </p>
        </form>
        <div class="grid gap-3 sm:grid-cols-3">
            <div
                v-for="metric in [
                    {
                        title: 'Customer spend',
                        value: report.totals.user_charge,
                    },
                    {
                        title: 'Provider cost',
                        value: report.totals.provider_cost,
                    },
                    { title: 'Gross profit', value: report.totals.profit },
                ]"
                :key="metric.title"
                class="rounded-xl border bg-card p-5"
            >
                <p class="text-xs text-muted-foreground">{{ metric.title }}</p>
                <p
                    class="mt-2 font-mono text-2xl"
                    :title="money(metric.value, 12)"
                >
                    {{ money(metric.value) }}
                </p>
            </div>
        </div>
        <div
            v-if="report.provider_error"
            role="status"
            class="rounded-lg border border-amber-500/30 bg-amber-500/5 p-4 text-sm"
        >
            {{ report.provider_error }}
        </div>
        <p
            v-else-if="report.provider_synced_at"
            class="text-xs text-muted-foreground"
        >
            Provider usage fetched
            {{ new Date(report.provider_synced_at).toLocaleString() }}. Totals
            cover this API key; generations still in progress may cause
            temporary differences.
        </p>
        <section class="overflow-hidden rounded-xl border bg-card">
            <div class="border-b p-5">
                <h2 class="font-semibold">Daily reconciliation</h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    Provider costs are compared independently against GET
                    /v1/usage.
                </p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/50 text-xs text-muted-foreground">
                        <tr>
                            <th class="p-4 font-medium">Date</th>
                            <th class="p-4 font-medium">Images</th>
                            <th class="p-4 font-medium">Customer spend</th>
                            <th class="p-4 font-medium">Local cost</th>
                            <th class="p-4 font-medium">Provider reports</th>
                            <th class="p-4 font-medium">Difference</th>
                            <th class="p-4 font-medium">Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="day in report.daily"
                            :key="day.date"
                            class="border-t"
                        >
                            <td class="p-4 whitespace-nowrap">
                                {{ day.date }}
                            </td>
                            <td class="p-4">{{ day.generations }}</td>
                            <td
                                class="p-4 font-mono"
                                :title="money(day.user_charge, 12)"
                            >
                                {{ money(day.user_charge) }}
                            </td>
                            <td
                                class="p-4 font-mono"
                                :title="money(day.provider_cost, 12)"
                            >
                                {{ money(day.provider_cost) }}
                            </td>
                            <td class="p-4 font-mono">
                                {{
                                    day.reported_provider_cost == null
                                        ? 'Unavailable'
                                        : money(day.reported_provider_cost)
                                }}
                            </td>
                            <td
                                :class="[
                                    'p-4 font-mono',
                                    day.difference != null &&
                                    day.difference !== '0'
                                        ? 'text-amber-600'
                                        : 'text-emerald-600',
                                ]"
                                :title="
                                    day.difference != null
                                        ? money(day.difference, 12)
                                        : ''
                                "
                            >
                                {{
                                    day.difference == null
                                        ? '—'
                                        : day.difference === '0'
                                          ? 'Matched'
                                          : money(day.difference)
                                }}<span
                                    v-if="
                                        day.reported_generations != null &&
                                        Number(day.reported_generations) !==
                                            Number(day.generations)
                                    "
                                    class="block text-xs text-amber-600"
                                    >Count differs (provider:
                                    {{ day.reported_generations }})</span
                                >
                            </td>
                            <td class="p-4 font-mono">
                                {{ money(day.profit) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
        <section class="overflow-hidden rounded-xl border bg-card">
            <h2 class="border-b p-5 font-semibold">Spend by user</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-muted/50 text-xs text-muted-foreground">
                        <tr>
                            <th class="p-4 font-medium">User</th>
                            <th class="p-4 font-medium">Generations</th>
                            <th class="p-4 font-medium">Customer spend</th>
                            <th class="p-4 font-medium">Provider cost</th>
                            <th class="p-4 font-medium">Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="user in report.users"
                            :key="user.user_id"
                            class="border-t"
                        >
                            <td class="p-4">
                                <span class="block font-medium">{{
                                    user.name
                                }}</span
                                ><span class="text-xs text-muted-foreground">{{
                                    user.email
                                }}</span>
                            </td>
                            <td class="p-4">{{ user.generations }}</td>
                            <td class="p-4 font-mono">
                                {{ money(user.user_charge) }}
                            </td>
                            <td class="p-4 font-mono">
                                {{ money(user.provider_cost) }}
                            </td>
                            <td class="p-4 font-mono">
                                {{ money(user.profit) }}
                            </td>
                        </tr>
                        <tr v-if="!report.users.length">
                            <td
                                colspan="5"
                                class="p-8 text-center text-muted-foreground"
                            >
                                No successful generations in this date range.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
