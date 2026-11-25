import { getPlatformLabel } from '@/composables/usePlatformLogo';

/**
 * A channel as "name · network" (Brand · Facebook), so channels that share
 * the brand's name can be told apart wherever a channel is named in text.
 * The network is left out when the name already says it ("Brand Facebook"),
 * and stands alone when the channel has no name (disconnected).
 */
export const channelLabel = (
    name: string | null | undefined,
    platform: string | null | undefined,
): string => {
    const shown = name?.trim() ?? '';
    const network = platform ? getPlatformLabel(platform) : '';

    if (!shown || !network) {
        return shown || network;
    }

    return shown.toLocaleLowerCase().includes(network.toLocaleLowerCase())
        ? shown
        : `${shown} · ${network}`;
};
