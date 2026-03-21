-- Supabase fixture: source database state (db1)
-- Uses Supabase idioms: uuid-ossp, timestamptz, RLS, triggers.

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

CREATE TABLE public.users (
    id          UUID DEFAULT uuid_generate_v4() PRIMARY KEY,
    email       TEXT UNIQUE NOT NULL,
    full_name   TEXT,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE public.posts (
    id          UUID DEFAULT uuid_generate_v4() PRIMARY KEY,
    title       TEXT NOT NULL,
    content     TEXT,
    author_id   UUID REFERENCES public.users(id) ON DELETE CASCADE,
    published   BOOLEAN DEFAULT false,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

ALTER TABLE public.users ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.posts ENABLE ROW LEVEL SECURITY;

CREATE OR REPLACE FUNCTION public.handle_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER users_updated_at BEFORE UPDATE ON public.users
    FOR EACH ROW EXECUTE FUNCTION public.handle_updated_at();

CREATE TRIGGER posts_updated_at BEFORE UPDATE ON public.posts
    FOR EACH ROW EXECUTE FUNCTION public.handle_updated_at();

INSERT INTO public.users (id, email, full_name) VALUES
    ('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'alice@example.com', 'Alice Smith'),
    ('b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', 'bob@example.com', 'Bob Jones');

INSERT INTO public.posts (id, title, content, author_id, published) VALUES
    ('c0eebc99-9c0b-4ef8-bb6d-6bb9bd380a33', 'Hello World', 'First post', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', true),
    ('d0eebc99-9c0b-4ef8-bb6d-6bb9bd380a44', 'Draft Post', 'WIP content', 'b0eebc99-9c0b-4ef8-bb6d-6bb9bd380a22', false);
