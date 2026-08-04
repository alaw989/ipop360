<script setup lang="ts">
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Button } from '@/components/ui/button';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    status?: string;
}>();

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(
    () => props.status === 'verification-link-sent',
);
</script>

<template>
    <GuestLayout>
        <Head title="Email Verification" />

        <h2 class="mb-4 text-center text-xl font-semibold text-white">Verify your email</h2>

        <p class="mb-6 text-center text-sm leading-relaxed text-white/70">
            Thanks for signing up! Before getting started, could you verify your
            email address by clicking on the link we just emailed to you? If you
            didn't receive the email, we will gladly send you another.
        </p>

        <div v-if="verificationLinkSent" class="mb-4 rounded-lg bg-emerald-400/10 px-4 py-3 text-sm font-medium text-emerald-300">
            A new verification link has been sent to the email address you
            provided during registration.
        </div>

        <form @submit.prevent="submit" class="space-y-5">
            <Button
                type="submit"
                size="lg"
                class="w-full bg-white text-neutral-900 hover:bg-white/90"
                :disabled="form.processing"
            >
                {{ form.processing ? 'Sending…' : 'Resend Verification Email' }}
            </Button>

            <Link
                :href="route('logout')"
                method="post"
                as="button"
                class="block w-full text-center text-sm text-white/70 underline-offset-4 hover:text-white hover:underline"
            >
                Log Out
            </Link>
        </form>
    </GuestLayout>
</template>
