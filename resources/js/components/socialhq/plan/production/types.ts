import type {
    BriefStage,
    PlanPerson,
} from '@/components/socialhq/plan/item/types';

export interface BoardCardData {
    id: number;
    item_id: number;
    title: string;
    kind: 'graphic' | 'video';
    stage: BriefStage;
    position: number;
    platform: string | null;
    channel: string | null;
    format: string | null;
    scheduled_on: string;
    due_on: string | null;
    assignee: PlanPerson | null;
    thumbnail_url: string | null;
    rounds: number;
}

export interface BoardFilters {
    mine: boolean;
    kind: 'graphic' | 'video' | null;
    month: string | null;
}
