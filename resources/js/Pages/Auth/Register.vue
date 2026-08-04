<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => {
            form.reset('password', 'password_confirmation');
        },
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Register" />

        <h2 class="mb-6 text-center text-xl font-semibold text-white">Create your account</h2>

        <form @submit.prevent="submit" class="space-y-5">
            <div>
                <label for="name" class="mb-1.5 block text-sm font-medium text-white/90">Name</label>
                <Input
                    id="name"
                    type="text"
                    class="h-10 bg-white/10 border-white/25 text-white placeholder:text-white/50 focus-visible:border-white/60 focus-visible:ring-white/30"
                    v-model="form.name"
                    required
                    autofocus
                    autocomplete="name"
                />
                <p v-if="form.errors.name" class="mt-1.5 text-sm text-red-300">{{ form.errors.name }}</p>
            </div>

            <div>
                <label for="email" class="mb-1.5 block text-sm font-medium text-white/90">Email</label>
                <Input
                    id="email"
                    type="email"
                    class="h-10 bg-white/10 border-white/25 text-white placeholder:text-white/50 focus-visible:border-white/60 focus-visible:ring-white/30"
                    v-model="form.email"
                    required
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
                {{ form.processing ? 'Creating account…' : 'Create account' }}
            </Button>

            <p class="pt-1 text-center text-sm text-white/60">
                Already have an account?
                <Link :href="route('login')" class="font-medium text-white underline-offset-4 hover:text-white hover:underline">
                    Sign in
                </Link>
            </p>
        </form>
    </GuestLayout>
</template>
