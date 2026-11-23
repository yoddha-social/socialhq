<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';

import {
    answerPostsChoice,
    type PostsChoice,
    postsChoice,
} from '@/components/socialhq/plan/postsChoice';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import date from '@/date';

// Social HQ: dropping or deleting a plan item whose post will still go out
// asks what happens to that post (postsChoice.ts). Back to drafts is the
// default, so the post never goes out for an item that left the plan unless
// someone says so.

const choice = ref<PostsChoice>('drafts');

watch(
    () => postsChoice.open,
    (open) => {
        if (open) {
            choice.value = 'drafts';
        }
    },
);

onBeforeUnmount(() => answerPostsChoice(null));

const onOpenChange = (open: boolean): void => {
    if (!open) {
        answerPostsChoice(null);
    }
};

const chooseOption = (value: unknown): void => {
    choice.value = value === 'keep' ? 'keep' : 'drafts';
};

const confirm = (): void => answerPostsChoice(choice.value);
</script>

<template>
    <!-- Rendered only while asking: its portal then mounts after the item sheet, so it stacks on top. -->
    <AlertDialog
        v-if="postsChoice.open"
        :open="postsChoice.open"
        @update:open="onOpenChange"
    >
        <AlertDialogContent
            class="gap-0 px-0 pt-6 pb-0 sm:max-w-[512px]"
            data-testid="plan-posts-choice"
        >
            <AlertDialogHeader class="gap-3 px-6 pb-4 text-left">
                <AlertDialogTitle class="font-sans text-base font-medium">
                    {{
                        postsChoice.mode === 'delete'
                            ? $t('socialhq_plan.posts_choice.delete_title')
                            : $t('socialhq_plan.posts_choice.drop_title')
                    }}
                </AlertDialogTitle>
                <AlertDialogDescription class="text-sm text-foreground">
                    {{
                        $tChoice(
                            'socialhq_plan.posts_choice.description',
                            postsChoice.posts.length,
                            { count: String(postsChoice.posts.length) },
                        )
                    }}
                </AlertDialogDescription>
                <ul class="flex flex-col gap-1.5 text-sm">
                    <li
                        v-for="post in postsChoice.posts"
                        :key="post.id"
                        class="flex flex-wrap items-center gap-x-2 gap-y-1"
                        :data-testid="`plan-posts-choice-post-${post.id}`"
                    >
                        <span class="font-medium">{{ post.channel }}</span>
                        <span
                            v-if="post.scheduled_at"
                            class="text-muted-foreground"
                            >{{ date.formatDateTime(post.scheduled_at) }}</span
                        >
                        <Badge
                            v-if="post.status === 'pending_approval'"
                            variant="warning"
                            class="px-1.5"
                        >
                            {{
                                $t(
                                    'socialhq_plan.posts_choice.waiting_approval',
                                )
                            }}
                        </Badge>
                    </li>
                </ul>
            </AlertDialogHeader>
            <RadioGroup
                :model-value="choice"
                class="gap-3 px-6 pb-5"
                :aria-label="$t('socialhq_plan.posts_choice.label')"
                @update:model-value="chooseOption"
            >
                <label class="flex cursor-pointer items-start gap-3">
                    <RadioGroupItem
                        value="drafts"
                        class="mt-0.5"
                        data-testid="plan-posts-choice-drafts"
                    />
                    <span class="min-w-0">
                        <span class="block text-sm leading-5 font-medium">
                            {{
                                $tChoice(
                                    'socialhq_plan.posts_choice.to_drafts',
                                    postsChoice.posts.length,
                                )
                            }}
                        </span>
                        <span class="block text-sm text-muted-foreground">
                            {{
                                $tChoice(
                                    'socialhq_plan.posts_choice.to_drafts_note',
                                    postsChoice.posts.length,
                                )
                            }}
                        </span>
                    </span>
                </label>
                <label class="flex cursor-pointer items-start gap-3">
                    <RadioGroupItem
                        value="keep"
                        class="mt-0.5"
                        data-testid="plan-posts-choice-keep"
                    />
                    <span class="min-w-0">
                        <span class="block text-sm leading-5 font-medium">
                            {{
                                $tChoice(
                                    'socialhq_plan.posts_choice.keep',
                                    postsChoice.posts.length,
                                )
                            }}
                        </span>
                        <span class="block text-sm text-muted-foreground">
                            {{
                                $tChoice(
                                    'socialhq_plan.posts_choice.keep_note',
                                    postsChoice.posts.length,
                                )
                            }}
                        </span>
                    </span>
                </label>
            </RadioGroup>
            <AlertDialogFooter class="mx-2 mb-2">
                <AlertDialogCancel data-testid="plan-posts-choice-cancel">
                    {{ $t('common.cancel') }}
                </AlertDialogCancel>
                <Button
                    size="lg"
                    :variant="
                        postsChoice.mode === 'delete'
                            ? 'destructive'
                            : 'default'
                    "
                    data-testid="plan-posts-choice-confirm"
                    @click="confirm"
                >
                    {{
                        postsChoice.mode === 'delete'
                            ? $t('socialhq_plan.posts_choice.delete')
                            : $t('socialhq_plan.posts_choice.drop')
                    }}
                </Button>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
