<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import PasswordController from '@/actions/App/Http/Controllers/Settings/PasswordController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/password';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Password settings',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Password settings" />

    <h1 class="sr-only">Password settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Update password"
            description="Ensure your account is using a long, random password to stay secure"
        />

        <Form
            v-bind="PasswordController.update.form()"
            :reset-on-success="['current_password', 'password', 'password_confirmation']"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="current_password">Current password</Label>
                <PasswordInput
                    id="current_password"
                    name="current_password"
                    class="mt-1 block w-full"
                    autocomplete="current-password"
                    placeholder="Current password"
                    required
                />
                <InputError :message="errors.current_password" class="mt-2" />
            </div>

            <div class="grid gap-2">
                <Label for="password">New password</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    placeholder="New password"
                    required
                />
                <InputError :message="errors.password" class="mt-2" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    placeholder="Confirm password"
                    required
                />
                <InputError :message="errors.password_confirmation" class="mt-2" />
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-password-button">
                    Save password
                </Button>
            </div>
        </Form>
    </div>
</template>
