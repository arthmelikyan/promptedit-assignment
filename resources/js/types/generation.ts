export type GenerationOutput = {
    id: number;
    position: number;
    media_type: string;
    url: string;
    expires_at: string | null;
    expired: boolean;
};
export type Generation = {
    id: string;
    type: 'image';
    prompt: string;
    settings: { size: string; quality: string; reference_images: string[] };
    status: 'queued' | 'processing' | 'succeeded' | 'failed';
    submission_state: string;
    estimated_min: string;
    estimated_max: string;
    actual_charge: string | null;
    usage: Record<string, number> | null;
    error_message: string | null;
    created_at: string;
    completed_at: string | null;
    outputs: GenerationOutput[];
};
export type Wallet = {
    balance: string;
    reserved: string;
    available: string;
    deficit: boolean;
};
export type GenerationPage = {
    data: Generation[];
    links: { url: string | null; label: string; active: boolean }[];
    meta: { current_page: number; last_page: number; total: number };
};
export type ReverbConfig = {
    key: string;
    host: string;
    port: number;
    scheme: string;
};
