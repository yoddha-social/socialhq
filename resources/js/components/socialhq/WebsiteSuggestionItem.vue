<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import type { WebsiteSuggestion } from '@/components/socialhq/brandSources';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { accept, skip } from '@/routes/socialhq/brand/website';

/**
 * One suggestion from "Read our website": Accept as it is, Edit then
 * accept, or Skip. Nothing is saved until it is accepted.
 */
const props = defineProps<{
    crawlId: number;
    suggestion: WebsiteSuggestion;
}>();

const isEditing = ref(false);
const isSkipping = ref(false);

const form = useForm<{
    value: string;
    title: string;
    body: string;
    name: string;
    hex: string;
}>({ value: '', title: '', body: '', name: '', hex: '' });

const errors = computed(() => form.errors as Record<string, string>);
const isOpen = computed(() => props.suggestion.status === 'open');
const canEdit = computed(() => props.suggestion.type !== 'document');
const target = computed(() => ({
    crawl: props.crawlId,
    suggestion: props.suggestion.key,
}));

/** The field an edit changes, by type. */
const editedFields = (): Record<string, string> => {
    switch (props.suggestion.type) {
        case 'entry':
            return { title: form.title, body: form.body };
        case 'colour':
            return { name: form.name, hex: form.hex };
        case 'document':
            return {};
        default:
            return { value: form.value };
    }
};

const startEdit = (): void => {
    const suggestion = props.suggestion;
    form.clearErrors();
    form.value = 'value' in suggestion ? suggestion.value : '';
    form.title = suggestion.type === 'entry' ? suggestion.title : '';
    form.body = suggestion.type === 'entry' ? suggestion.body : '';
    form.name = suggestion.type === 'colour' ? suggestion.name : '';
    form.hex = suggestion.type === 'colour' ? suggestion.hex : '';
    isEditing.value = true;
};

const cancelEdit = (): void => {
    isEditing.value = false;
};

const acceptSuggestion = (): void => {
    form.transform(() => (isEditing.value ? editedFields() : {})).post(
        accept.url(target.value),
        {
            preserveScroll: true,
            onSuccess: () => {
                isEditing.value = false;
            },
        },
    );
};

const skipSuggestion = (): void => {
    router.post(
        skip.url(target.value),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                isSkipping.value = true;
            },
            onFinish: () => {
                isSkipping.value = false;
            },
        },
    );
};
</script>

<template>
    <li
        class="flex flex-col gap-3 rounded-lg border bg-card p-4"
        :class="{ 'opacity-70': !isOpen }"
        :data-testid="`socialhq-website-suggestion-${suggestion.key}`"
    >
        <div class="flex flex-wrap items-center gap-2">
            <Badge variant="secondary">
                {{
                    $t(
                        `socialhq_knowledge.website.review.types.${suggestion.type}`,
                    )
                }}
            </Badge>
            <span
                v-if="suggestion.type === 'profile'"
                class="text-sm font-medium text-foreground"
            >
                {{ $t(`socialhq.brand.profile.${suggestion.field}`) }}
            </span>
            <span
                v-else-if="suggestion.type === 'font'"
                class="text-sm font-medium text-foreground"
            >
                {{ $t(`socialhq_knowledge.look.${suggestion.field}`) }}
            </span>
            <span
                v-else-if="suggestion.type === 'entry'"
                class="text-sm text-muted-foreground"
            >
                {{ $t(`socialhq.brand.entries.kinds.${suggestion.kind}`) }}
            </span>
            <Badge
                v-if="suggestion.status === 'accepted'"
                variant="success"
                :data-testid="`socialhq-website-suggestion-status-${suggestion.key}`"
            >
                {{ $t('socialhq_knowledge.website.review.accepted') }}
            </Badge>
            <Badge
                v-else-if="suggestion.status === 'skipped'"
                variant="outline"
                :data-testid="`socialhq-website-suggestion-status-${suggestion.key}`"
            >
                {{ $t('socialhq_knowledge.website.review.skipped') }}
            </Badge>
        </div>

        <template v-if="!isEditing">
            <template v-if="suggestion.type === 'entry'">
                <p class="text-sm font-medium text-foreground">
                    {{ suggestion.title }}
                </p>
                <p class="text-sm whitespace-pre-line text-muted-foreground">
                    {{ suggestion.body }}
                </p>
            </template>
            <div
                v-else-if="suggestion.type === 'colour'"
                class="flex items-center gap-3"
            >
                <span
                    class="size-8 shrink-0 rounded-md border"
                    :style="{ backgroundColor: suggestion.hex }"
                    aria-hidden="true"
                />
                <span class="text-sm text-foreground">{{
                    suggestion.name
                }}</span>
                <span class="font-mono text-sm text-muted-foreground">{{
                    suggestion.hex
                }}</span>
            </div>
            <p
                v-else-if="suggestion.type === 'document'"
                class="text-sm text-muted-foreground"
            >
                {{ $t('socialhq_knowledge.website.review.document') }}
            </p>
            <p v-else class="text-sm whitespace-pre-line text-foreground">
                {{ suggestion.value }}
            </p>
            <p
                v-if="isOpen && suggestion.type === 'profile'"
                class="text-xs text-muted-foreground"
            >
                {{ $t('socialhq_knowledge.website.review.replaces') }}
            </p>
        </template>

        <div v-else class="flex flex-col gap-2">
            <template v-if="suggestion.type === 'entry'">
                <Input
                    v-model="form.title"
                    :aria-label="$t('socialhq.brand.entries.dialog.title')"
                    :data-testid="`socialhq-website-suggestion-title-${suggestion.key}`"
                />
                <Textarea
                    v-model="form.body"
                    :rows="3"
                    :aria-label="$t('socialhq.brand.entries.dialog.body')"
                />
            </template>
            <div
                v-else-if="suggestion.type === 'colour'"
                class="flex flex-wrap gap-2"
            >
                <Input
                    v-model="form.name"
                    class="min-w-0 flex-1"
                    :aria-label="$t('socialhq_knowledge.look.colour_name')"
                />
                <Input
                    v-model="form.hex"
                    class="w-28 font-mono"
                    :aria-label="$t('socialhq_knowledge.look.colour_hex')"
                />
            </div>
            <Input
                v-else-if="suggestion.type === 'font'"
                v-model="form.value"
                :aria-label="$t(`socialhq_knowledge.look.${suggestion.field}`)"
            />
            <Textarea
                v-else
                v-model="form.value"
                :rows="3"
                :aria-label="
                    $t(
                        `socialhq_knowledge.website.review.types.${suggestion.type}`,
                    )
                "
                :data-testid="`socialhq-website-suggestion-value-${suggestion.key}`"
            />
        </div>

        <p
            v-for="(message, field) in errors"
            :key="field"
            role="alert"
            class="text-sm text-destructive-text"
        >
            {{ message }}
        </p>

        <div v-if="isOpen" class="flex flex-wrap gap-2">
            <template v-if="isEditing">
                <Button type="button" variant="ghost" @click="cancelEdit">
                    {{ $t('socialhq_knowledge.website.review.cancel') }}
                </Button>
                <Button
                    type="button"
                    :loading="form.processing"
                    :data-testid="`socialhq-website-suggestion-accept-${suggestion.key}`"
                    @click="acceptSuggestion"
                >
                    {{ $t('socialhq_knowledge.website.review.accept_edited') }}
                </Button>
            </template>
            <template v-else>
                <Button
                    type="button"
                    variant="ghost"
                    :loading="isSkipping"
                    :data-testid="`socialhq-website-suggestion-skip-${suggestion.key}`"
                    @click="skipSuggestion"
                >
                    {{ $t('socialhq_knowledge.website.review.skip') }}
                </Button>
                <Button
                    v-if="canEdit"
                    type="button"
                    variant="outline"
                    :data-testid="`socialhq-website-suggestion-edit-${suggestion.key}`"
                    @click="startEdit"
                >
                    {{ $t('socialhq_knowledge.website.review.edit') }}
                </Button>
                <Button
                    type="button"
                    :loading="form.processing"
                    :data-testid="`socialhq-website-suggestion-accept-${suggestion.key}`"
                    @click="acceptSuggestion"
                >
                    {{ $t('socialhq_knowledge.website.review.accept') }}
                </Button>
            </template>
        </div>
    </li>
</template>
