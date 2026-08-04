<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{
    canResetPassword?: boolean;
    status?: string;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => {
            form.reset('password');
        },
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Log in" />

        <h2 class="mb-6 text-center text-xl font-semibold text-white">Sign in to your account</h2>

        <div v-if="status" class="mb-4 text-sm font-medium text-emerald-300">
            {{ status }}
        </div>

        <form @submit.prevent="submit" class="space-y-5">
            <div>
                <label for="email" class="mb-1.5 block text-sm font-medium text-white/90">Email</label>
                <Input
                    id="email"
                    type="email"
                    class="h-10 bg-white/10 border-white/25 text-white placeholder:text-white/50 focus-visible:border-white/60 focus-visible:ring-white/30"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                />
                <p v-if="form.errors.email" class="mt-1.5 text-sm text-red-300">{{ form.errors.email }}</p>
            </div>

            <div>
                <div class="mb-1.5 flex items-center justify-between">
                    <label for="password" class="block text-sm font-medium text-white/90">Password</label>
                    <Link
                        v-if="canResetPassword"
                        :href="route('password.request')"
                        class="text-sm text-white/70 underline-offset-4 hover:text-white hover:underline"
                    >
                        Forgot your password?
                    </Link>
                </div>
                <Input
                    id="password"
                    type="password"
                    class="h-10 bg-white/10 border-white/25 text-white placeholder:text-white/50 focus-visible:border-white/60 focus-visible:ring-white/30"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                />
                <p v-if="form.errors.password" class="mt-1.5 text-sm text-red-300">{{ form.errors.password }}</p>
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm text-white/80">
                    <input
                        type="checkbox"
                        name="remember"
                        v-model="form.remember"
                        class="h-4 w-4 rounded border-white/30 bg-white/10 accent-white"
                    />
                    Remember me
                </label>
            </div>

            <Button
                type="submit"
                size="lg"
                class="w-full bg-white text-neutral-900 hover:bg-white/90"
                :disabled="form.processing"
            >
                {{ form.processing ? 'Signing in…' : 'Sign in' }}
            </Button>

            <p class="pt-1 text-center text-sm text-white/60">
                New here?
                <Link :href="route('register')" class="font-medium text-white underline-offset-4 hover:text-white hover:underline">
                    Create an account
                </Link>
            </p>
        </form>
    </GuestLayout>
</template>
