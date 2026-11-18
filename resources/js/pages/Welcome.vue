<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { login, register, studio } from '@/routes';
</script>

<template>
    <Head title="Welcome" />
    <main
        class="flex min-h-screen items-center justify-center bg-background text-foreground"
    >
        <nav
            class="flex flex-wrap items-center justify-center gap-4 p-6"
            aria-label="Account"
        >
            <Button
                v-if="$page.props.auth.user"
                as-child
                size="lg"
                class="h-12 min-w-36 px-8 text-base"
                ><Link :href="studio()">Open studio</Link></Button
            >
            <template v-else>
                <Button as-child size="lg" class="h-12 min-w-36 px-8 text-base"
                    ><Link :href="login()">Log in</Link></Button
                >
                <Button
                    as-child
                    variant="outline"
                    size="lg"
                    class="h-12 min-w-36 px-8 text-base"
                    ><Link :href="register()">Register</Link></Button
                >
            </template>
        </nav>
    </main>
</template>
