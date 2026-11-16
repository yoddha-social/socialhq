import dayjs from '@/dayjs';
import { activeLocale } from '@/language';

/**
 * A dayjs date in the page's language. `dayjs.locale()` is global and not
 * reactive, so a computed that formats through this follows a language switch.
 */
export const localizedDay = (value?: dayjs.ConfigType): dayjs.Dayjs =>
    dayjs(value).locale(activeLocale.value.toLowerCase());
