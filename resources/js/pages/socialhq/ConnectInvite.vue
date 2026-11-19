<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { IconCheck, IconCircleCheckFilled } from '@tabler/icons-vue';
import { computed } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/AuthLayout.vue';

interface InviteSummary {
    workspace: string;
    platform: string;
    channel: string;
    network: string;
    inviter: string | null;
    reconnecting: boolean;
}

interface InviteIdentity {
    key: string;
    platform: string;
    name: string | null;
    username: string | null;
    avatar: string | null;
    locked: boolean;
}

type InviteState =
    | 'intro'
    | 'select'
    | 'done'
    | 'expired'
    | 'signed_in'
    | 'error';

const props = defineProps<{
    state: InviteState;
    invite?: InviteSummary;
    startUrl?: string;
    retryUrl?: string;
    finishUrl?: string;
    identities?: InviteIdentity[];
    reason?: { key: string | null; text: string | null };
    done?: { workspace: string | null; platform: string; channels: string[] };
}>();

const STEPS = ['sign_in', 'pick', 'publish'] as const;

const cards = computed(() => props.identities ?? []);
const selectable = computed(() => cards.value.filter((card) => !card.locked));

const form = useForm<{ identities: string[] }>({
    identities: selectable.value.length === 1 ? [selectable.value[0].key] : [],
});

const isPicked = (card: InviteIdentity): boolean =>
    form.identities.includes(card.key);

const toggle = (card: InviteIdentity): void => {
    if (card.locked) {
        return;
    }

    form.identities = isPicked(card)
        ? form.identities.filter((key) => key !== card.key)
        : [...form.identities, card.key];
};

const leaving = useForm({});

const start = (): void => {
    if (props.startUrl) {
        leaving.post(props.startUrl);
    }
};

const retry = (): void => {
    if (props.retryUrl) {
        leaving.post(props.retryUrl);
    }
};

const finish = (): void => {
    if (props.finishUrl) {
        form.post(props.finishUrl);
    }
};

const cardName = (card: InviteIdentity): string =>
    card.name || card.username || '';
</script>

<template>
    <AuthLayout width="md">
        <Head :title="$t('socialhq.connect_invite.page_title')" />

        <div
            class="flex flex-col gap-6"
            data-testid="connect-invite"
            :data-state="state"
        >
            <template v-if="state === 'intro' && invite">
                <div class="flex flex-col gap-2">
                    <h1
                        class="font-heading text-xl font-medium text-foreground"
                        data-testid="connect-invite-title"
                    >
                        {{
                            invite.reconnecting
                                ? $t(
                                      'socialhq.connect_invite.intro.title_reconnect',
                                      {
                                          channel: invite.channel,
                                          workspace: invite.workspace,
                                      },
                                  )
                                : $t('socialhq.connect_invite.intro.title', {
                                      channel: invite.channel,
                                      workspace: invite.workspace,
                                  })
                        }}
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        {{
                            invite.inviter
                                ? $t(
                                      'socialhq.connect_invite.intro.description',
                                      {
                                          inviter: invite.inviter,
                                          channel: invite.channel,
                                          workspace: invite.workspace,
                                      },
                                  )
                                : $t(
                                      'socialhq.connect_invite.intro.description_no_inviter',
                                      {
                                          channel: invite.channel,
                                          workspace: invite.workspace,
                                      },
                                  )
                        }}
                    </p>
                </div>
                <ol class="flex flex-col gap-3">
                    <li
                        v-for="(step, index) in STEPS"
                        :key="step"
                        class="flex gap-3 text-sm text-foreground"
                    >
                        <span
                            class="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium text-muted-foreground"
                            aria-hidden="true"
                            >{{ index + 1 }}</span
                        >
                        <span>{{
                            $t(`socialhq.connect_invite.intro.steps.${step}`, {
                                network: invite.network,
                                workspace: invite.workspace,
                            })
                        }}</span>
                    </li>
                </ol>
                <div class="flex flex-col gap-2">
                    <Button
                        size="lg"
                        :loading="leaving.processing"
                        data-testid="connect-invite-continue"
                        @click="start"
                    >
                        {{
                            $t('socialhq.connect_invite.intro.continue', {
                                network: invite.network,
                            })
                        }}
                    </Button>
                    <p class="text-center text-xs text-muted-foreground">
                        {{ $t('socialhq.connect_invite.intro.no_account') }}
                    </p>
                </div>
            </template>

            <template v-else-if="state === 'select' && invite">
                <div class="flex flex-col gap-2">
                    <h1
                        class="font-heading text-xl font-medium text-foreground"
                        data-testid="connect-invite-title"
                    >
                        {{ $t('socialhq.connect_invite.select.title') }}
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        {{
                            $t('socialhq.connect_invite.select.description', {
                                network: invite.network,
                                workspace: invite.workspace,
                            })
                        }}
                    </p>
                </div>
                <ul
                    class="flex flex-col gap-2"
                    data-testid="connect-invite-identities"
                >
                    <li v-for="card in cards" :key="card.key">
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 rounded-xl border p-3 text-start transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                            :class="[
                                card.locked
                                    ? 'cursor-default border-border bg-muted/40'
                                    : 'border-border hover:border-border-strong',
                                isPicked(card)
                                    ? 'border-primary-strong ring-1 ring-primary-strong'
                                    : '',
                            ]"
                            role="checkbox"
                            :aria-checked="isPicked(card)"
                            :aria-disabled="card.locked"
                            :data-testid="`connect-invite-identity-${card.key}`"
                            @click="toggle(card)"
                        >
                            <ChannelAvatar
                                :platform="card.platform"
                                :src="card.avatar"
                                :name="cardName(card)"
                                :size="40"
                            />
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-sm font-emphasis text-foreground"
                                    >{{ cardName(card) }}</span
                                >
                                <span
                                    v-if="
                                        card.username &&
                                        card.username !== cardName(card)
                                    "
                                    class="block truncate text-xs text-muted-foreground"
                                    >@{{ card.username }}</span
                                >
                            </span>
                            <span
                                v-if="card.locked"
                                class="inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground"
                            >
                                <IconCheck class="size-4" />
                                {{
                                    $t(
                                        'socialhq.connect_invite.select.connected',
                                    )
                                }}
                            </span>
                            <span
                                v-else
                                class="inline-flex size-5 shrink-0 items-center justify-center rounded-md border"
                                :class="
                                    isPicked(card)
                                        ? 'border-primary-strong bg-primary-strong text-primary-foreground'
                                        : 'border-border-strong'
                                "
                                aria-hidden="true"
                            >
                                <IconCheck
                                    v-if="isPicked(card)"
                                    class="size-3.5"
                                />
                            </span>
                        </button>
                    </li>
                </ul>
                <InputError
                    :message="
                        form.errors.identities ?? Object.values(form.errors)[0]
                    "
                />
                <Button
                    size="lg"
                    :loading="form.processing"
                    :disabled="form.identities.length === 0"
                    data-testid="connect-invite-finish"
                    @click="finish"
                >
                    {{ $t('socialhq.connect_invite.select.finish') }}
                </Button>
            </template>

            <template v-else-if="state === 'done' && done">
                <div
                    class="flex flex-col items-center gap-3 text-center"
                    data-testid="connect-invite-done"
                >
                    <IconCircleCheckFilled class="size-10 text-success" />
                    <h1
                        class="font-heading text-xl font-medium text-foreground"
                    >
                        {{ $t('socialhq.connect_invite.done.title') }}
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        {{
                            done.channels.length > 0
                                ? $tChoice(
                                      'socialhq.connect_invite.done.description',
                                      done.channels.length,
                                      {
                                          channels: done.channels.join(', '),
                                          workspace: done.workspace ?? '',
                                      },
                                  )
                                : $t(
                                      'socialhq.connect_invite.done.description_plain',
                                      { workspace: done.workspace ?? '' },
                                  )
                        }}
                    </p>
                </div>
            </template>

            <template v-else-if="state === 'error' && invite">
                <div
                    class="flex flex-col gap-2"
                    data-testid="connect-invite-error"
                >
                    <h1
                        class="font-heading text-xl font-medium text-foreground"
                    >
                        {{ $t('socialhq.connect_invite.error.title') }}
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        {{
                            reason?.key
                                ? $t(reason.key, { network: invite.network })
                                : reason?.text
                        }}
                    </p>
                </div>
                <Button
                    size="lg"
                    :loading="leaving.processing"
                    data-testid="connect-invite-retry"
                    @click="retry"
                >
                    {{ $t('socialhq.connect_invite.error.retry') }}
                </Button>
            </template>

            <div
                v-else
                class="flex flex-col gap-2"
                :data-testid="`connect-invite-${state === 'signed_in' ? 'signed-in' : 'expired'}`"
            >
                <h1 class="font-heading text-xl font-medium text-foreground">
                    {{
                        state === 'signed_in'
                            ? $t('socialhq.connect_invite.signed_in.title')
                            : $t('socialhq.connect_invite.expired.title')
                    }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{
                        state === 'signed_in'
                            ? $t(
                                  'socialhq.connect_invite.signed_in.description',
                              )
                            : $t('socialhq.connect_invite.expired.description')
                    }}
                </p>
            </div>
        </div>
    </AuthLayout>
</template>
