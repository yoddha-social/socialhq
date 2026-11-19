export type NoticeTone = 'success' | 'danger' | 'warning' | 'info';

export interface NoticeAction {
    label: string;
    href: string;
    external: boolean;
}

export interface NoticeItem {
    id: number;
    kind: string;
    tone: NoticeTone;
    title: string;
    body: string;
    created_at: string;
    read: boolean;
    actions: NoticeAction[];
}

export interface NoticeList {
    notices: NoticeItem[];
    unread: number;
}

/** What the server shares for the bell (`socialhq.notices`). */
export interface NoticeShare {
    unread: number;
    channel: string;
}
