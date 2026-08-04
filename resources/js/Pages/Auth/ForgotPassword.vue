<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Head, useForm } from '@inertiajs/vue3';

defineProps<{
    status?: string;
}>();

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Forgot Password" />

        <h2 class="mb-4 text-center text-xl font-semibold text-white">Reset your password</h2>

        <p class="mb-6 text-center text-sm leading-relaxed text-white/70">
            Forgot your password? No problem. Just let us know your email address
            and we will email you a password reset link that will allow you to
            choose a new one.
        </p>

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

            <Button
                type="submit"
                size="lg"
                class="w-full bg-white text-neutral-900 hover:bg-white/90"
                :disabled="form.processing"
            >
                {{ form.processing ? 'Sending…' : 'Email Password Reset Link' }}
            </Button>
        </form>
    </GuestLayout>
</template>
