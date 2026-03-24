-- UP: Add posts table with FK to users
CREATE TABLE public.posts (
    id          UUID DEFAULT uuid_generate_v4() PRIMARY KEY,
    title       TEXT NOT NULL,
    content     TEXT,
    author_id   UUID REFERENCES public.users(id) ON DELETE CASCADE,
    published   BOOLEAN DEFAULT false,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

ALTER TABLE public.posts ENABLE ROW LEVEL SECURITY;
