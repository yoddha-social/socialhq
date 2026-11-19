import { useHttp } from '@inertiajs/vue3';
import { reactive } from 'vue';

import type {
    NoticeItem,
    NoticeList,
} from '@/components/socialhq/notices/notices';
import { index, read } from '@/routes/socialhq/notices';

/**
 * What the bell and the live notices share. The bell lives in the page header
 * and the listener in the app layout, so a page without a header still hears
 * new notices.
 */
export const noticeState = reactive({
    unread: 0,
    notices: null as NoticeItem[] | null,
    loading: false,
    failed: false,
    panelOpen: false,
});

export const useNotices = () => {
    const listHttp = useHttp<Record<string, never>, NoticeList>({});
    const readHttp = useHttp<{ ids?: number[] }, { unread: number }>({});

    const load = async (): Promise<NoticeList | null> => {
        noticeState.loading = true;
        noticeState.failed = false;

        try {
            const list = await listHttp.get(index.url());

            noticeState.notices = list.notices;
            noticeState.unread = list.unread;

            return list;
        } catch {
            noticeState.failed = true;

            return null;
        } finally {
            noticeState.loading = false;
        }
    };

    /** Marks the given notices read, or every notice when none is given. */
    const markRead = async (ids?: number[]): Promise<void> => {
        const wanted = (notice: NoticeItem): boolean =>
            ids === undefined || ids.includes(notice.id);

        noticeState.notices =
            noticeState.notices?.map((notice) =>
                wanted(notice) ? { ...notice, read: true } : notice,
            ) ?? null;

        try {
            const answer = await readHttp
                .transform(() => (ids === undefined ? {} : { ids }))
                .post(read.url());

            noticeState.unread = answer.unread;
        } catch {
            // The count is right again on the next page or the next time the bell opens.
        }
    };

    return { load, markRead };
};
