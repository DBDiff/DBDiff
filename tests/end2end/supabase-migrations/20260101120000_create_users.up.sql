-- UP: Create initial Supabase-style schema with UUID PKs and RLS
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

CREATE TABLE public.users (
    id          UUID DEFAULT uuid_generate_v4() PRIMARY KEY,
    email       TEXT UNIQUE NOT NULL,
    full_name   TEXT,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

ALTER TABLE public.users ENABLE ROW LEVEL SECURITY;
