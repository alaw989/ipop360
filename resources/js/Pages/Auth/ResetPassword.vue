<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    email: string;
    token: string;
}>();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('password.store'), {
        onFinish: () => {
            form.reset('password', 'password_confirmation');
        },
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Reset Password" />

        <h2 class="mb-6 text-center text-xl font-semibold text-white">Set a new password</h2>

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
                <label for="password" class="mb-1.5 block text-sm font-medium text-white/90">Password</label>
                <Input
                    id="password"
                    type="password"
                    class="h-10 bg-white/10 border-white/25 text-white placeholder:text-white/50 focus-visible:border-white/60 focus-visible:ring-white/30"
                    v-model="form.password"
                    required
                    autocomplete="new-password"
                />
                <p v-if="form.errors.password" class="mt-1.5 text-sm text-red-300">{{ form.errors.password }}</p>
            </div>

            <div>
                <label for="password_confirmation" class="mb-1.5 block text-sm font-medium text-white/90">Confirm Password</label>
                <Input
                    id="password_confirmation"
                    type="password"
                    class="h-10 bg-white/10 border-white/25 text-white placeholder:text-white/50 focus-visible:border-white/60 focus-visible:ring-white/30"
                    v-model="form.password_confirmation"
                    required
                    autocomplete="new-password"
                />
                <p v-if="form.errors.password_confirmation" class="mt-1.5 text-sm text-red-300">{{ form.errors.password_confirmation }}</p>
            </div>

            <Button
                type="submit"
                size="lg"
                class="w-full bg-white text-neutral-900 hover:bg-white/90"
                :disabled="form.processing"
            >
                {{ form.processing ? 'Resetting…' : 'Reset Password' }}
            </Button>
        </form>
    </GuestLayout>
</template>
