<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { useCompanySignup } from '@/components/socialhq/signup';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

// Social HQ: the register form's company name, for someone who asks to
// start a company. Hidden with an invite; the server decides on submit
// whether the name is needed (CompanySignup::asksForCompany).

defineProps<{
    invite?: string | null;
    error?: string;
}>();

const companySignup = useCompanySignup();
</script>

<template>
    <div v-if="companySignup.asks && !invite" class="grid gap-2">
        <Label for="company_name">{{
            $t('socialhq_companies.signup.name_label')
        }}</Label>
        <Input
            id="company_name"
            data-testid="register-company-name"
            type="text"
            :tabindex="2"
            autocomplete="organization"
            name="company_name"
            :placeholder="$t('socialhq_companies.signup.name_placeholder')"
        />
        <p
            class="text-sm text-muted-foreground"
            data-testid="register-company-name-hint"
        >
            {{
                companySignup.joinsByEmail
                    ? $t('socialhq_companies.signup.name_hint_lists')
                    : $t('socialhq_companies.signup.name_hint')
            }}
        </p>
        <InputError :message="error" />
    </div>
</template>
