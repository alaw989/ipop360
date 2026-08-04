<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
});

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => {
            form.reset();
        },
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Confirm Password" />

        <h2 class="mb-4 text-center text-xl font-semibold text-white">Confirm your password</h2>

        <p class="mb-6 text-center text-sm leading-relaxed text-white/70">
            This is a secure area of the application. Please confirm your
            password before continuing.
        </p>

        <form @submit.prevent="submit" class="space-y-5">
            <div>
                <label for="password" class="mb-1.5 block text-sm font-medium text-white/90">Password</label>
                <Input
                    id="password"
                    type="password"
                    class="h-10 bg-white/10 border-white/25 text-white placeholder:text-white/50 focus-visible:border-white/60 focus-visible:ring-white/30"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                    autofocus
                />
                <p v-if="form.errors.password" class="mt-1.5 text-sm text-red-300">{{ form.errors.password }}</p>
            </div>

            <Button
                type="submit"
                size="lg"
                class="w-full bg-white text-neutral-900 hover:bg-white/90"
                :disabled="form.processing"
            >
                {{ form.processing ? 'Confirming…' : 'Confirm' }}
            </Button>
        </form>
    </GuestLayout>
</template>
