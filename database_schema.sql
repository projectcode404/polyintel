--
-- PostgreSQL database dump
--

\restrict RmZEpRex6VY1VOt9XKDbaqY9mkvsIkBogFIhsRc6lfc20UYnShGVJdAsV2PsBcZ

-- Dumped from database version 15.17
-- Dumped by pg_dump version 15.17

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: public; Type: SCHEMA; Schema: -; Owner: sikapiapsby
--

-- *not* creating schema, since initdb creates it


ALTER SCHEMA public OWNER TO sikapiapsby;

--
-- Name: SCHEMA public; Type: COMMENT; Schema: -; Owner: sikapiapsby
--

COMMENT ON SCHEMA public IS '';


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: ai_predictions; Type: TABLE; Schema: public; Owner: sikapiapsby
--

CREATE TABLE public.ai_predictions (
    id bigint NOT NULL,
    market_id bigint NOT NULL,
    model_name character varying(100) NOT NULL,
    model_version character varying(50),
    engine_version character varying(20),
    probability_estimate numeric(8,6) NOT NULL,
    confidence numeric(8,6) NOT NULL,
    market_probability_at_prediction numeric(8,6) NOT NULL,
    edge numeric(8,6) NOT NULL,
    features_snapshot json NOT NULL,
    is_scored boolean DEFAULT false NOT NULL,
    brier_score numeric(8,6),
    was_correct boolean,
    raw_response text,
    prompt_tokens integer,
    completion_tokens integer,
    predicted_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.ai_predictions OWNER TO sikapiapsby;

--
-- Name: COLUMN ai_predictions.model_name; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.ai_predictions.model_name IS 'e.g. gpt-4o, claude-opus-4, gemini-2-pro, custom-v1';


--
-- Name: COLUMN ai_predictions.model_version; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.ai_predictions.model_version IS 'Specific version or checkpoint';


--
-- Name: COLUMN ai_predictions.engine_version; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.ai_predictions.engine_version IS 'Our ProbabilityEngine wrapper version';


--
-- Name: COLUMN ai_predictions.probability_estimate; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.ai_predictions.probability_estimate IS 'AI estimated probability for YES (0–1)';


--
-- Name: COLUMN ai_predictions.confidence; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.ai_predictions.confidence IS 'Model confidence in its estimate (0–1)';


--
-- Name: COLUMN ai_predictions.market_probability_at_prediction; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.ai_predictions.market_probability_at_prediction IS 'Market price when AI ran — needed to compute edge';


--
-- Name: COLUMN ai_predictions.edge; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.ai_predictions.edge IS 'probability_estimate − market_probability_at_prediction';


--
-- Name: COLUMN ai_predictions.features_snapshot; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.ai_predictions.features_snapshot IS 'All input features: btc_price, eth_price, funding_rate, etc.';


--
-- Name: COLUMN ai_predictions.is_scored; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.ai_predictions.is_scored IS 'True after market_outcomes record exists';


--
-- Name: COLUMN ai_predictions.brier_score; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.ai_predictions.brier_score IS '(probability_estimate − outcome)² — lower is better';


--
-- Name: COLUMN ai_predictions.was_correct; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.ai_predictions.was_correct IS 'True if AI edge direction matched actual outcome';


--
-- Name: COLUMN ai_predictions.raw_response; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.ai_predictions.raw_response IS 'Full API response from AI provider';


--
-- Name: COLUMN ai_predictions.predicted_at; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.ai_predictions.predicted_at IS 'When the AI prediction was generated';


--
-- Name: ai_predictions_id_seq; Type: SEQUENCE; Schema: public; Owner: sikapiapsby
--

CREATE SEQUENCE public.ai_predictions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.ai_predictions_id_seq OWNER TO sikapiapsby;

--
-- Name: ai_predictions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: sikapiapsby
--

ALTER SEQUENCE public.ai_predictions_id_seq OWNED BY public.ai_predictions.id;


--
-- Name: cache; Type: TABLE; Schema: public; Owner: sikapiapsby
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


ALTER TABLE public.cache OWNER TO sikapiapsby;

--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: sikapiapsby
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


ALTER TABLE public.cache_locks OWNER TO sikapiapsby;

--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: sikapiapsby
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE public.failed_jobs OWNER TO sikapiapsby;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: sikapiapsby
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.failed_jobs_id_seq OWNER TO sikapiapsby;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: sikapiapsby
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: sikapiapsby
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


ALTER TABLE public.job_batches OWNER TO sikapiapsby;

--
-- Name: jobs; Type: TABLE; Schema: public; Owner: sikapiapsby
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


ALTER TABLE public.jobs OWNER TO sikapiapsby;

--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: sikapiapsby
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.jobs_id_seq OWNER TO sikapiapsby;

--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: sikapiapsby
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: market_daily_stats; Type: TABLE; Schema: public; Owner: sikapiapsby
--

CREATE TABLE public.market_daily_stats (
    id bigint NOT NULL,
    market_id bigint NOT NULL,
    stat_date date NOT NULL,
    volume_7d_avg_usd numeric(20,2),
    oi_change_percent numeric(10,6),
    momentum_24h_percent numeric(10,6),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.market_daily_stats OWNER TO sikapiapsby;

--
-- Name: COLUMN market_daily_stats.volume_7d_avg_usd; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.market_daily_stats.volume_7d_avg_usd IS 'Precomputed 7-day average volume for quick lookup';


--
-- Name: COLUMN market_daily_stats.oi_change_percent; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.market_daily_stats.oi_change_percent IS 'Open interest change percent vs previous day';


--
-- Name: COLUMN market_daily_stats.momentum_24h_percent; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.market_daily_stats.momentum_24h_percent IS 'Probability change in last 24h';


--
-- Name: market_daily_stats_id_seq; Type: SEQUENCE; Schema: public; Owner: sikapiapsby
--

CREATE SEQUENCE public.market_daily_stats_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.market_daily_stats_id_seq OWNER TO sikapiapsby;

--
-- Name: market_daily_stats_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: sikapiapsby
--

ALTER SEQUENCE public.market_daily_stats_id_seq OWNED BY public.market_daily_stats.id;


--
-- Name: market_outcomes; Type: TABLE; Schema: public; Owner: sikapiapsby
--

CREATE TABLE public.market_outcomes (
    id bigint NOT NULL,
    market_id bigint NOT NULL,
    winning_side character varying(20) NOT NULL,
    resolution_price numeric(8,6) NOT NULL,
    final_probability_before_resolution numeric(8,6),
    peak_probability_yes numeric(8,6),
    low_probability_yes numeric(8,6),
    total_volume_usd numeric(20,2) DEFAULT '0'::numeric NOT NULL,
    resolved_by character varying(200),
    resolution_notes text,
    resolved_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.market_outcomes OWNER TO sikapiapsby;

--
-- Name: COLUMN market_outcomes.winning_side; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.market_outcomes.winning_side IS 'yes | no | cancelled';


--
-- Name: COLUMN market_outcomes.resolution_price; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.market_outcomes.resolution_price IS 'Final YES token settlement price: 1.0 = YES, 0.0 = NO';


--
-- Name: COLUMN market_outcomes.final_probability_before_resolution; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.market_outcomes.final_probability_before_resolution IS 'Last observed market_probability before resolution';


--
-- Name: COLUMN market_outcomes.peak_probability_yes; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.market_outcomes.peak_probability_yes IS 'Highest YES probability observed during market lifetime';


--
-- Name: COLUMN market_outcomes.low_probability_yes; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.market_outcomes.low_probability_yes IS 'Lowest YES probability observed during market lifetime';


--
-- Name: COLUMN market_outcomes.total_volume_usd; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.market_outcomes.total_volume_usd IS 'Total volume traded over market lifetime';


--
-- Name: COLUMN market_outcomes.resolved_by; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.market_outcomes.resolved_by IS 'Oracle / resolver identifier from Polymarket';


--
-- Name: COLUMN market_outcomes.resolution_notes; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.market_outcomes.resolution_notes IS 'Any additional context about the resolution';


--
-- Name: COLUMN market_outcomes.resolved_at; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.market_outcomes.resolved_at IS 'Timestamp of official resolution';


--
-- Name: market_outcomes_id_seq; Type: SEQUENCE; Schema: public; Owner: sikapiapsby
--

CREATE SEQUENCE public.market_outcomes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.market_outcomes_id_seq OWNER TO sikapiapsby;

--
-- Name: market_outcomes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: sikapiapsby
--

ALTER SEQUENCE public.market_outcomes_id_seq OWNED BY public.market_outcomes.id;


--
-- Name: market_snapshots; Type: TABLE; Schema: public; Owner: sikapiapsby
--

CREATE TABLE public.market_snapshots (
    id bigint NOT NULL,
    market_id bigint NOT NULL,
    probability_yes numeric(8,6) NOT NULL,
    probability_no numeric(8,6) NOT NULL,
    best_bid numeric(8,6),
    best_ask numeric(8,6),
    spread numeric(8,6),
    volume_usd numeric(20,2) DEFAULT '0'::numeric NOT NULL,
    volume_24h_usd numeric(20,2) DEFAULT '0'::numeric NOT NULL,
    liquidity_usd numeric(20,2) DEFAULT '0'::numeric NOT NULL,
    btc_price_usd numeric(20,2),
    eth_price_usd numeric(20,2),
    fear_greed_index smallint,
    btc_dominance numeric(6,4),
    collector_version character varying(20),
    snapshotted_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.market_snapshots OWNER TO sikapiapsby;

--
-- Name: COLUMN market_snapshots.probability_yes; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.market_snapshots.probability_yes IS 'YES token probability at snapshot time (0–1)';


--
-- Name: COLUMN market_snapshots.probability_no; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.market_snapshots.probability_no IS 'NO token probability at snapshot time (0–1)';


--
-- Name: COLUMN market_snapshots.best_bid; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.market_snapshots.best_bid IS 'Best bid price for YES token';


--
-- Name: COLUMN market_snapshots.best_ask; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.market_snapshots.best_ask IS 'Best ask price for YES token';


--
-- Name: COLUMN market_snapshots.spread; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.market_snapshots.spread IS 'best_ask - best_bid; computed on insert';


--
-- Name: COLUMN market_snapshots.volume_24h_usd; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.market_snapshots.volume_24h_usd IS 'Rolling 24-hour volume';


--
-- Name: COLUMN market_snapshots.btc_price_usd; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.market_snapshots.btc_price_usd IS 'BTC spot price at snapshot time';


--
-- Name: COLUMN market_snapshots.eth_price_usd; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.market_snapshots.eth_price_usd IS 'ETH spot price at snapshot time';


--
-- Name: COLUMN market_snapshots.fear_greed_index; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.market_snapshots.fear_greed_index IS 'Crypto Fear & Greed index 0–100';


--
-- Name: COLUMN market_snapshots.btc_dominance; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.market_snapshots.btc_dominance IS 'BTC market dominance % as decimal e.g. 0.5234';


--
-- Name: COLUMN market_snapshots.collector_version; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.market_snapshots.collector_version IS 'Python collector version that created this row';


--
-- Name: COLUMN market_snapshots.snapshotted_at; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.market_snapshots.snapshotted_at IS 'The actual observation time (NOT created_at)';


--
-- Name: market_snapshots_id_seq; Type: SEQUENCE; Schema: public; Owner: sikapiapsby
--

CREATE SEQUENCE public.market_snapshots_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.market_snapshots_id_seq OWNER TO sikapiapsby;

--
-- Name: market_snapshots_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: sikapiapsby
--

ALTER SEQUENCE public.market_snapshots_id_seq OWNED BY public.market_snapshots.id;


--
-- Name: markets; Type: TABLE; Schema: public; Owner: sikapiapsby
--

CREATE TABLE public.markets (
    id bigint NOT NULL,
    condition_id character varying(100) NOT NULL,
    slug character varying(200),
    question character varying(500) NOT NULL,
    description text,
    category character varying(50) DEFAULT 'crypto'::character varying NOT NULL,
    sub_category character varying(100),
    tags character varying(255),
    resolution_source character varying(300),
    start_date timestamp(0) without time zone,
    end_date timestamp(0) without time zone,
    resolved_at timestamp(0) without time zone,
    status character varying(30) DEFAULT 'active'::character varying NOT NULL,
    market_probability numeric(8,6),
    volume_usd numeric(20,2) DEFAULT '0'::numeric NOT NULL,
    liquidity_usd numeric(20,2) DEFAULT '0'::numeric NOT NULL,
    num_traders integer,
    ai_probability numeric(8,6),
    edge numeric(8,6),
    last_synced_at timestamp(0) without time zone,
    is_tracked boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    volume_24h_usd numeric(20,2) DEFAULT '0'::numeric NOT NULL,
    best_bid numeric(8,6),
    best_ask numeric(8,6),
    spread numeric(8,6),
    price_change_1h numeric(10,6),
    price_change_1d numeric(10,6)
);


ALTER TABLE public.markets OWNER TO sikapiapsby;

--
-- Name: COLUMN markets.condition_id; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.markets.condition_id IS 'Polymarket CLOB condition ID';


--
-- Name: COLUMN markets.slug; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.markets.slug IS 'Polymarket URL slug';


--
-- Name: COLUMN markets.question; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.markets.question IS 'The full market question text';


--
-- Name: COLUMN markets.description; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.markets.description IS 'Long-form market description';


--
-- Name: COLUMN markets.category; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.markets.category IS 'Market category: crypto, politics, sports, etc.';


--
-- Name: COLUMN markets.sub_category; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.markets.sub_category IS 'e.g. bitcoin, ethereum, defi';


--
-- Name: COLUMN markets.tags; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.markets.tags IS 'JSON array of tags for filtering';


--
-- Name: COLUMN markets.resolution_source; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.markets.resolution_source IS 'Who/what resolves this market';


--
-- Name: COLUMN markets.start_date; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.markets.start_date IS 'When the market opened';


--
-- Name: COLUMN markets.end_date; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.markets.end_date IS 'Scheduled resolution date';


--
-- Name: COLUMN markets.resolved_at; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.markets.resolved_at IS 'Actual resolution timestamp';


--
-- Name: COLUMN markets.status; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.markets.status IS 'active | resolved | cancelled | paused';


--
-- Name: COLUMN markets.market_probability; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.markets.market_probability IS 'Latest YES probability from Polymarket (0.000000 – 1.000000)';


--
-- Name: COLUMN markets.volume_usd; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.markets.volume_usd IS 'Total trading volume in USD';


--
-- Name: COLUMN markets.liquidity_usd; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.markets.liquidity_usd IS 'Current open liquidity in USD';


--
-- Name: COLUMN markets.ai_probability; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.markets.ai_probability IS 'AI-estimated probability (populated in Sprint 4)';


--
-- Name: COLUMN markets.edge; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.markets.edge IS 'ai_probability - market_probability; NULL until AI runs';


--
-- Name: COLUMN markets.last_synced_at; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.markets.last_synced_at IS 'Last time Python collector fetched this market';


--
-- Name: COLUMN markets.is_tracked; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.markets.is_tracked IS 'False = stop collecting snapshots for this market';


--
-- Name: markets_id_seq; Type: SEQUENCE; Schema: public; Owner: sikapiapsby
--

CREATE SEQUENCE public.markets_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.markets_id_seq OWNER TO sikapiapsby;

--
-- Name: markets_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: sikapiapsby
--

ALTER SEQUENCE public.markets_id_seq OWNED BY public.markets.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: sikapiapsby
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


ALTER TABLE public.migrations OWNER TO sikapiapsby;

--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: sikapiapsby
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.migrations_id_seq OWNER TO sikapiapsby;

--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: sikapiapsby
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: model_has_permissions; Type: TABLE; Schema: public; Owner: sikapiapsby
--

CREATE TABLE public.model_has_permissions (
    permission_id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    model_id bigint NOT NULL
);


ALTER TABLE public.model_has_permissions OWNER TO sikapiapsby;

--
-- Name: model_has_roles; Type: TABLE; Schema: public; Owner: sikapiapsby
--

CREATE TABLE public.model_has_roles (
    role_id bigint NOT NULL,
    model_type character varying(255) NOT NULL,
    model_id bigint NOT NULL
);


ALTER TABLE public.model_has_roles OWNER TO sikapiapsby;

--
-- Name: paper_trade_history; Type: TABLE; Schema: public; Owner: sikapiapsby
--

CREATE TABLE public.paper_trade_history (
    id bigint NOT NULL,
    paper_trade_id bigint NOT NULL,
    event_type character varying(255) NOT NULL,
    price_at_event numeric(15,8) NOT NULL,
    shares_affected numeric(15,8) DEFAULT '0'::numeric NOT NULL,
    pnl_realized numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    reason text,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT paper_trade_history_event_type_check CHECK (((event_type)::text = ANY (ARRAY[('OPENED'::character varying)::text, ('PARTIAL_CLOSE'::character varying)::text, ('TP1'::character varying)::text, ('TP2'::character varying)::text, ('TP3'::character varying)::text, ('STOP_LOSS'::character varying)::text, ('BREAKEVEN_MOVED'::character varying)::text, ('SMART_EXIT'::character varying)::text, ('CLOSED'::character varying)::text, ('EXPIRED'::character varying)::text])))
);


ALTER TABLE public.paper_trade_history OWNER TO sikapiapsby;

--
-- Name: paper_trade_history_id_seq; Type: SEQUENCE; Schema: public; Owner: sikapiapsby
--

CREATE SEQUENCE public.paper_trade_history_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.paper_trade_history_id_seq OWNER TO sikapiapsby;

--
-- Name: paper_trade_history_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: sikapiapsby
--

ALTER SEQUENCE public.paper_trade_history_id_seq OWNED BY public.paper_trade_history.id;


--
-- Name: paper_trade_settings; Type: TABLE; Schema: public; Owner: sikapiapsby
--

CREATE TABLE public.paper_trade_settings (
    id bigint NOT NULL,
    initial_capital numeric(15,2) DEFAULT '1000'::numeric NOT NULL,
    max_portfolio_exposure_percent numeric(5,2) DEFAULT '50'::numeric NOT NULL,
    max_concurrent_trades integer DEFAULT 10 NOT NULL,
    reserve_cash_percent numeric(5,2) DEFAULT '20'::numeric NOT NULL,
    max_position_per_market integer DEFAULT 1 NOT NULL,
    market_cooldown_minutes integer DEFAULT 60 NOT NULL,
    position_size_mode character varying(255) DEFAULT 'fixed_percent'::character varying NOT NULL,
    fixed_amount numeric(15,2),
    fixed_percent numeric(5,2) DEFAULT '2'::numeric,
    enable_dynamic_position_size boolean DEFAULT false NOT NULL,
    enable_top_signal_filter boolean DEFAULT true NOT NULL,
    max_signals_per_cycle integer DEFAULT 10 NOT NULL,
    minimum_signal_score numeric(8,4) DEFAULT 0.7 NOT NULL,
    enable_take_profit boolean DEFAULT true NOT NULL,
    take_profit_mode character varying(255) DEFAULT 'r_multiple'::character varying NOT NULL,
    take_profit_r1 numeric(5,2) DEFAULT '1'::numeric,
    take_profit_r2 numeric(5,2),
    take_profit_r3 numeric(5,2),
    enable_stop_loss boolean DEFAULT true NOT NULL,
    stop_loss_mode character varying(255) DEFAULT 'r_multiple'::character varying NOT NULL,
    stop_loss_value numeric(5,2) DEFAULT '1'::numeric NOT NULL,
    enable_move_to_breakeven boolean DEFAULT true NOT NULL,
    breakeven_trigger_r numeric(5,2) DEFAULT '1'::numeric NOT NULL,
    enable_partial_take_profit boolean DEFAULT false NOT NULL,
    partial_tp1_percent numeric(5,2) DEFAULT '50'::numeric,
    partial_tp2_percent numeric(5,2) DEFAULT '30'::numeric,
    partial_tp3_percent numeric(5,2) DEFAULT '20'::numeric,
    enable_smart_exit boolean DEFAULT true NOT NULL,
    preset character varying(255) DEFAULT 'balanced'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT paper_trade_settings_position_size_mode_check CHECK (((position_size_mode)::text = ANY (ARRAY[('fixed_amount'::character varying)::text, ('fixed_percent'::character varying)::text, ('dynamic'::character varying)::text]))),
    CONSTRAINT paper_trade_settings_preset_check CHECK (((preset)::text = ANY (ARRAY[('conservative'::character varying)::text, ('balanced'::character varying)::text, ('aggressive'::character varying)::text, ('custom'::character varying)::text]))),
    CONSTRAINT paper_trade_settings_stop_loss_mode_check CHECK (((stop_loss_mode)::text = ANY (ARRAY[('fixed_percent'::character varying)::text, ('r_multiple'::character varying)::text]))),
    CONSTRAINT paper_trade_settings_take_profit_mode_check CHECK (((take_profit_mode)::text = ANY (ARRAY[('fixed_percent'::character varying)::text, ('r_multiple'::character varying)::text])))
);


ALTER TABLE public.paper_trade_settings OWNER TO sikapiapsby;

--
-- Name: paper_trade_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: sikapiapsby
--

CREATE SEQUENCE public.paper_trade_settings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.paper_trade_settings_id_seq OWNER TO sikapiapsby;

--
-- Name: paper_trade_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: sikapiapsby
--

ALTER SEQUENCE public.paper_trade_settings_id_seq OWNED BY public.paper_trade_settings.id;


--
-- Name: paper_trades; Type: TABLE; Schema: public; Owner: sikapiapsby
--

CREATE TABLE public.paper_trades (
    id bigint NOT NULL,
    market_id bigint NOT NULL,
    signal_id bigint,
    direction character varying(10) NOT NULL,
    entry_price numeric(8,6) NOT NULL,
    exit_price numeric(8,6),
    shares numeric(20,6) NOT NULL,
    position_size_usd numeric(20,2) NOT NULL,
    fees_usd numeric(10,4) DEFAULT '0'::numeric NOT NULL,
    pnl_usd numeric(20,4),
    roi numeric(10,6),
    current_price numeric(8,6),
    unrealized_pnl_usd numeric(20,4),
    max_adverse_excursion numeric(10,6),
    max_favorable_excursion numeric(10,6),
    market_probability_at_entry numeric(8,6) NOT NULL,
    ai_probability_at_entry numeric(8,6),
    edge_at_entry numeric(8,6) NOT NULL,
    status character varying(30) DEFAULT 'open'::character varying NOT NULL,
    outcome character varying(20),
    holding_period_hours numeric(10,4),
    notes text,
    entered_at timestamp(0) without time zone NOT NULL,
    exited_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    trading_account_id bigint,
    signal_score numeric(8,4),
    position_size_mode character varying(30),
    take_profit_price numeric(8,6),
    stop_loss_price numeric(8,6),
    breakeven_price numeric(8,6),
    exit_reason character varying(50),
    smart_exit_reason text
);


ALTER TABLE public.paper_trades OWNER TO sikapiapsby;

--
-- Name: COLUMN paper_trades.direction; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.paper_trades.direction IS 'yes | no — which token we bought';


--
-- Name: COLUMN paper_trades.entry_price; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.paper_trades.entry_price IS 'Price of YES/NO token at entry (0–1)';


--
-- Name: COLUMN paper_trades.exit_price; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.paper_trades.exit_price IS 'Price at exit; NULL while position is open';


--
-- Name: COLUMN paper_trades.shares; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.paper_trades.shares IS 'Number of tokens purchased';


--
-- Name: COLUMN paper_trades.position_size_usd; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.paper_trades.position_size_usd IS 'USD deployed = shares × entry_price';


--
-- Name: COLUMN paper_trades.fees_usd; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.paper_trades.fees_usd IS 'Estimated trading fees (2% Polymarket fee)';


--
-- Name: COLUMN paper_trades.pnl_usd; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.paper_trades.pnl_usd IS '(exit_price - entry_price) × shares - fees_usd';


--
-- Name: COLUMN paper_trades.roi; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.paper_trades.roi IS 'pnl_usd / position_size_usd (e.g. 0.25 = 25% return)';


--
-- Name: COLUMN paper_trades.current_price; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.paper_trades.current_price IS 'Current market price (updated while open)';


--
-- Name: COLUMN paper_trades.unrealized_pnl_usd; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.paper_trades.unrealized_pnl_usd IS 'Unrealized PnL for open positions';


--
-- Name: COLUMN paper_trades.max_adverse_excursion; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.paper_trades.max_adverse_excursion IS 'Worst price movement against our position (for drawdown)';


--
-- Name: COLUMN paper_trades.max_favorable_excursion; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.paper_trades.max_favorable_excursion IS 'Best price movement in our favour';


--
-- Name: COLUMN paper_trades.market_probability_at_entry; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.paper_trades.market_probability_at_entry IS 'Market probability when we entered';


--
-- Name: COLUMN paper_trades.ai_probability_at_entry; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.paper_trades.ai_probability_at_entry IS 'AI probability at entry';


--
-- Name: COLUMN paper_trades.edge_at_entry; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.paper_trades.edge_at_entry IS 'Edge when we entered the position';


--
-- Name: COLUMN paper_trades.status; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.paper_trades.status IS 'open | closed | cancelled';


--
-- Name: COLUMN paper_trades.outcome; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.paper_trades.outcome IS 'win | loss | breakeven | cancelled — set on close';


--
-- Name: COLUMN paper_trades.holding_period_hours; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.paper_trades.holding_period_hours IS 'Duration of trade in hours — set on close';


--
-- Name: COLUMN paper_trades.entered_at; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.paper_trades.entered_at IS 'When we entered the paper trade';


--
-- Name: COLUMN paper_trades.exited_at; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.paper_trades.exited_at IS 'When we closed the paper trade';


--
-- Name: paper_trades_id_seq; Type: SEQUENCE; Schema: public; Owner: sikapiapsby
--

CREATE SEQUENCE public.paper_trades_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.paper_trades_id_seq OWNER TO sikapiapsby;

--
-- Name: paper_trades_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: sikapiapsby
--

ALTER SEQUENCE public.paper_trades_id_seq OWNED BY public.paper_trades.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: sikapiapsby
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


ALTER TABLE public.password_reset_tokens OWNER TO sikapiapsby;

--
-- Name: permissions; Type: TABLE; Schema: public; Owner: sikapiapsby
--

CREATE TABLE public.permissions (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    guard_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.permissions OWNER TO sikapiapsby;

--
-- Name: permissions_id_seq; Type: SEQUENCE; Schema: public; Owner: sikapiapsby
--

CREATE SEQUENCE public.permissions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.permissions_id_seq OWNER TO sikapiapsby;

--
-- Name: permissions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: sikapiapsby
--

ALTER SEQUENCE public.permissions_id_seq OWNED BY public.permissions.id;


--
-- Name: role_has_permissions; Type: TABLE; Schema: public; Owner: sikapiapsby
--

CREATE TABLE public.role_has_permissions (
    permission_id bigint NOT NULL,
    role_id bigint NOT NULL
);


ALTER TABLE public.role_has_permissions OWNER TO sikapiapsby;

--
-- Name: roles; Type: TABLE; Schema: public; Owner: sikapiapsby
--

CREATE TABLE public.roles (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    guard_name character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.roles OWNER TO sikapiapsby;

--
-- Name: roles_id_seq; Type: SEQUENCE; Schema: public; Owner: sikapiapsby
--

CREATE SEQUENCE public.roles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.roles_id_seq OWNER TO sikapiapsby;

--
-- Name: roles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: sikapiapsby
--

ALTER SEQUENCE public.roles_id_seq OWNED BY public.roles.id;


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: sikapiapsby
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


ALTER TABLE public.sessions OWNER TO sikapiapsby;

--
-- Name: signals; Type: TABLE; Schema: public; Owner: sikapiapsby
--

CREATE TABLE public.signals (
    id bigint NOT NULL,
    market_id bigint NOT NULL,
    ai_prediction_id bigint,
    direction character varying(10) NOT NULL,
    market_probability_at_signal numeric(8,6) NOT NULL,
    ai_probability_at_signal numeric(8,6),
    edge_at_signal numeric(8,6) NOT NULL,
    confidence_at_signal numeric(8,6),
    min_edge_threshold numeric(8,6) DEFAULT 0.05 NOT NULL,
    trigger_source character varying(50) DEFAULT 'edge_threshold'::character varying NOT NULL,
    status character varying(30) DEFAULT 'pending'::character varying NOT NULL,
    notes text,
    fired_at timestamp(0) without time zone NOT NULL,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone,
    snapshot_data json,
    resolved_outcome character varying(20),
    is_correct boolean,
    realized_roi numeric(10,4),
    resolved_at timestamp(0) without time zone,
    momentum_24h_percent numeric(10,6),
    liquidity_usd numeric(20,2),
    volume_24h_usd numeric(20,2),
    spread numeric(8,6)
);


ALTER TABLE public.signals OWNER TO sikapiapsby;

--
-- Name: COLUMN signals.direction; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.signals.direction IS 'yes | no — which side we are signalling';


--
-- Name: COLUMN signals.market_probability_at_signal; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.signals.market_probability_at_signal IS 'Market probability when signal was generated';


--
-- Name: COLUMN signals.ai_probability_at_signal; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.signals.ai_probability_at_signal IS 'AI probability when signal was generated (NULL if rule-based)';


--
-- Name: COLUMN signals.edge_at_signal; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.signals.edge_at_signal IS 'Edge when signal fired (positive = favourable)';


--
-- Name: COLUMN signals.confidence_at_signal; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.signals.confidence_at_signal IS 'AI confidence at signal time';


--
-- Name: COLUMN signals.min_edge_threshold; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.signals.min_edge_threshold IS 'Minimum edge configured when signal fired';


--
-- Name: COLUMN signals.trigger_source; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.signals.trigger_source IS 'edge_threshold | ai_engine | manual';


--
-- Name: COLUMN signals.status; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.signals.status IS 'pending | active | closed | cancelled';


--
-- Name: COLUMN signals.fired_at; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.signals.fired_at IS 'When the signal was generated';


--
-- Name: COLUMN signals.expires_at; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.signals.expires_at IS 'Signals for time-sensitive markets expire';


--
-- Name: COLUMN signals.snapshot_data; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.signals.snapshot_data IS 'Stores context at entry: rule_name, confidence, price_entry, volume_7d, oi_change, momentum';


--
-- Name: COLUMN signals.resolved_outcome; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.signals.resolved_outcome IS 'yes | no | cancelled — copied from market_outcomes.winning_side';


--
-- Name: COLUMN signals.is_correct; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.signals.is_correct IS 'True if signal direction matched winning_side. NULL = not yet evaluated.';


--
-- Name: COLUMN signals.realized_roi; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.signals.realized_roi IS 'ROI % based on entry probability and outcome. NULL = not yet evaluated.';


--
-- Name: COLUMN signals.resolved_at; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.signals.resolved_at IS 'Copied from market_outcomes.resolved_at — actual market resolution time.';


--
-- Name: signals_id_seq; Type: SEQUENCE; Schema: public; Owner: sikapiapsby
--

CREATE SEQUENCE public.signals_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.signals_id_seq OWNER TO sikapiapsby;

--
-- Name: signals_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: sikapiapsby
--

ALTER SEQUENCE public.signals_id_seq OWNED BY public.signals.id;


--
-- Name: system_settings; Type: TABLE; Schema: public; Owner: sikapiapsby
--

CREATE TABLE public.system_settings (
    id bigint NOT NULL,
    key character varying(100) NOT NULL,
    value text,
    type character varying(20) DEFAULT 'string'::character varying NOT NULL,
    description character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.system_settings OWNER TO sikapiapsby;

--
-- Name: COLUMN system_settings.type; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.system_settings.type IS 'string | boolean | integer | float | json';


--
-- Name: system_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: sikapiapsby
--

CREATE SEQUENCE public.system_settings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.system_settings_id_seq OWNER TO sikapiapsby;

--
-- Name: system_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: sikapiapsby
--

ALTER SEQUENCE public.system_settings_id_seq OWNED BY public.system_settings.id;


--
-- Name: trading_accounts; Type: TABLE; Schema: public; Owner: sikapiapsby
--

CREATE TABLE public.trading_accounts (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    balance numeric(20,4) DEFAULT '1000'::numeric NOT NULL,
    is_auto_trade boolean DEFAULT false NOT NULL,
    is_auto_close boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    deleted_at timestamp(0) without time zone
);


ALTER TABLE public.trading_accounts OWNER TO sikapiapsby;

--
-- Name: COLUMN trading_accounts.balance; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.trading_accounts.balance IS 'Available USD for paper trading';


--
-- Name: COLUMN trading_accounts.is_auto_trade; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.trading_accounts.is_auto_trade IS 'If true, automatically enter trade when signal is fired';


--
-- Name: COLUMN trading_accounts.is_auto_close; Type: COMMENT; Schema: public; Owner: sikapiapsby
--

COMMENT ON COLUMN public.trading_accounts.is_auto_close IS 'If true, automatically close trade when market resolves';


--
-- Name: trading_accounts_id_seq; Type: SEQUENCE; Schema: public; Owner: sikapiapsby
--

CREATE SEQUENCE public.trading_accounts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.trading_accounts_id_seq OWNER TO sikapiapsby;

--
-- Name: trading_accounts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: sikapiapsby
--

ALTER SEQUENCE public.trading_accounts_id_seq OWNED BY public.trading_accounts.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: sikapiapsby
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.users OWNER TO sikapiapsby;

--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: sikapiapsby
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE public.users_id_seq OWNER TO sikapiapsby;

--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: sikapiapsby
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: ai_predictions id; Type: DEFAULT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.ai_predictions ALTER COLUMN id SET DEFAULT nextval('public.ai_predictions_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: market_daily_stats id; Type: DEFAULT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.market_daily_stats ALTER COLUMN id SET DEFAULT nextval('public.market_daily_stats_id_seq'::regclass);


--
-- Name: market_outcomes id; Type: DEFAULT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.market_outcomes ALTER COLUMN id SET DEFAULT nextval('public.market_outcomes_id_seq'::regclass);


--
-- Name: market_snapshots id; Type: DEFAULT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.market_snapshots ALTER COLUMN id SET DEFAULT nextval('public.market_snapshots_id_seq'::regclass);


--
-- Name: markets id; Type: DEFAULT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.markets ALTER COLUMN id SET DEFAULT nextval('public.markets_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: paper_trade_history id; Type: DEFAULT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.paper_trade_history ALTER COLUMN id SET DEFAULT nextval('public.paper_trade_history_id_seq'::regclass);


--
-- Name: paper_trade_settings id; Type: DEFAULT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.paper_trade_settings ALTER COLUMN id SET DEFAULT nextval('public.paper_trade_settings_id_seq'::regclass);


--
-- Name: paper_trades id; Type: DEFAULT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.paper_trades ALTER COLUMN id SET DEFAULT nextval('public.paper_trades_id_seq'::regclass);


--
-- Name: permissions id; Type: DEFAULT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.permissions ALTER COLUMN id SET DEFAULT nextval('public.permissions_id_seq'::regclass);


--
-- Name: roles id; Type: DEFAULT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.roles ALTER COLUMN id SET DEFAULT nextval('public.roles_id_seq'::regclass);


--
-- Name: signals id; Type: DEFAULT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.signals ALTER COLUMN id SET DEFAULT nextval('public.signals_id_seq'::regclass);


--
-- Name: system_settings id; Type: DEFAULT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.system_settings ALTER COLUMN id SET DEFAULT nextval('public.system_settings_id_seq'::regclass);


--
-- Name: trading_accounts id; Type: DEFAULT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.trading_accounts ALTER COLUMN id SET DEFAULT nextval('public.trading_accounts_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: ai_predictions ai_predictions_pkey; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.ai_predictions
    ADD CONSTRAINT ai_predictions_pkey PRIMARY KEY (id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: market_daily_stats market_daily_stats_pkey; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.market_daily_stats
    ADD CONSTRAINT market_daily_stats_pkey PRIMARY KEY (id);


--
-- Name: market_outcomes market_outcomes_market_id_unique; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.market_outcomes
    ADD CONSTRAINT market_outcomes_market_id_unique UNIQUE (market_id);


--
-- Name: market_outcomes market_outcomes_pkey; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.market_outcomes
    ADD CONSTRAINT market_outcomes_pkey PRIMARY KEY (id);


--
-- Name: market_snapshots market_snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.market_snapshots
    ADD CONSTRAINT market_snapshots_pkey PRIMARY KEY (id);


--
-- Name: markets markets_condition_id_unique; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.markets
    ADD CONSTRAINT markets_condition_id_unique UNIQUE (condition_id);


--
-- Name: markets markets_pkey; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.markets
    ADD CONSTRAINT markets_pkey PRIMARY KEY (id);


--
-- Name: markets markets_slug_unique; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.markets
    ADD CONSTRAINT markets_slug_unique UNIQUE (slug);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: market_daily_stats mkt_daily_stats_unique; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.market_daily_stats
    ADD CONSTRAINT mkt_daily_stats_unique UNIQUE (market_id, stat_date);


--
-- Name: model_has_permissions model_has_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.model_has_permissions
    ADD CONSTRAINT model_has_permissions_pkey PRIMARY KEY (permission_id, model_id, model_type);


--
-- Name: model_has_roles model_has_roles_pkey; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.model_has_roles
    ADD CONSTRAINT model_has_roles_pkey PRIMARY KEY (role_id, model_id, model_type);


--
-- Name: paper_trade_history paper_trade_history_pkey; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.paper_trade_history
    ADD CONSTRAINT paper_trade_history_pkey PRIMARY KEY (id);


--
-- Name: paper_trade_settings paper_trade_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.paper_trade_settings
    ADD CONSTRAINT paper_trade_settings_pkey PRIMARY KEY (id);


--
-- Name: paper_trades paper_trades_pkey; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.paper_trades
    ADD CONSTRAINT paper_trades_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: permissions permissions_name_guard_name_unique; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_name_guard_name_unique UNIQUE (name, guard_name);


--
-- Name: permissions permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.permissions
    ADD CONSTRAINT permissions_pkey PRIMARY KEY (id);


--
-- Name: role_has_permissions role_has_permissions_pkey; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_pkey PRIMARY KEY (permission_id, role_id);


--
-- Name: roles roles_name_guard_name_unique; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_name_guard_name_unique UNIQUE (name, guard_name);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: signals signals_pkey; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.signals
    ADD CONSTRAINT signals_pkey PRIMARY KEY (id);


--
-- Name: system_settings system_settings_key_unique; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.system_settings
    ADD CONSTRAINT system_settings_key_unique UNIQUE (key);


--
-- Name: system_settings system_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.system_settings
    ADD CONSTRAINT system_settings_pkey PRIMARY KEY (id);


--
-- Name: trading_accounts trading_accounts_pkey; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.trading_accounts
    ADD CONSTRAINT trading_accounts_pkey PRIMARY KEY (id);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: ai_pred_brier_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX ai_pred_brier_idx ON public.ai_predictions USING btree (brier_score);


--
-- Name: ai_pred_edge_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX ai_pred_edge_idx ON public.ai_predictions USING btree (edge);


--
-- Name: ai_pred_market_time_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX ai_pred_market_time_idx ON public.ai_predictions USING btree (market_id, predicted_at);


--
-- Name: ai_pred_model_scored_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX ai_pred_model_scored_idx ON public.ai_predictions USING btree (model_name, is_scored);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: markets_edge_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX markets_edge_idx ON public.markets USING btree (edge);


--
-- Name: markets_end_date_status_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX markets_end_date_status_idx ON public.markets USING btree (end_date, status);


--
-- Name: markets_last_synced_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX markets_last_synced_idx ON public.markets USING btree (last_synced_at);


--
-- Name: markets_price_change_1d_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX markets_price_change_1d_idx ON public.markets USING btree (price_change_1d);


--
-- Name: markets_probability_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX markets_probability_idx ON public.markets USING btree (market_probability);


--
-- Name: markets_status_category_tracked_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX markets_status_category_tracked_idx ON public.markets USING btree (status, category, is_tracked);


--
-- Name: markets_volume_24h_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX markets_volume_24h_idx ON public.markets USING btree (volume_24h_usd);


--
-- Name: model_has_permissions_model_id_model_type_index; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX model_has_permissions_model_id_model_type_index ON public.model_has_permissions USING btree (model_id, model_type);


--
-- Name: model_has_roles_model_id_model_type_index; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX model_has_roles_model_id_model_type_index ON public.model_has_roles USING btree (model_id, model_type);


--
-- Name: outcomes_resolved_at_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX outcomes_resolved_at_idx ON public.market_outcomes USING btree (resolved_at);


--
-- Name: outcomes_winning_side_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX outcomes_winning_side_idx ON public.market_outcomes USING btree (winning_side);


--
-- Name: paper_trade_history_created_at_index; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX paper_trade_history_created_at_index ON public.paper_trade_history USING btree (created_at);


--
-- Name: paper_trade_history_event_type_index; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX paper_trade_history_event_type_index ON public.paper_trade_history USING btree (event_type);


--
-- Name: paper_trade_history_paper_trade_id_event_type_index; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX paper_trade_history_paper_trade_id_event_type_index ON public.paper_trade_history USING btree (paper_trade_id, event_type);


--
-- Name: paper_trade_history_paper_trade_id_index; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX paper_trade_history_paper_trade_id_index ON public.paper_trade_history USING btree (paper_trade_id);


--
-- Name: paper_trades_exit_reason_index; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX paper_trades_exit_reason_index ON public.paper_trades USING btree (exit_reason);


--
-- Name: paper_trades_signal_score_index; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX paper_trades_signal_score_index ON public.paper_trades USING btree (signal_score);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: signals_direction_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX signals_direction_idx ON public.signals USING btree (direction);


--
-- Name: signals_edge_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX signals_edge_idx ON public.signals USING btree (edge_at_signal);


--
-- Name: signals_is_correct_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX signals_is_correct_idx ON public.signals USING btree (is_correct);


--
-- Name: signals_liquidity_usd_index; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX signals_liquidity_usd_index ON public.signals USING btree (liquidity_usd);


--
-- Name: signals_market_status_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX signals_market_status_idx ON public.signals USING btree (market_id, status);


--
-- Name: signals_momentum_24h_percent_index; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX signals_momentum_24h_percent_index ON public.signals USING btree (momentum_24h_percent);


--
-- Name: signals_resolved_at_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX signals_resolved_at_idx ON public.signals USING btree (resolved_at);


--
-- Name: signals_roi_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX signals_roi_idx ON public.signals USING btree (realized_roi);


--
-- Name: signals_source_correct_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX signals_source_correct_idx ON public.signals USING btree (trigger_source, is_correct);


--
-- Name: signals_source_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX signals_source_idx ON public.signals USING btree (trigger_source);


--
-- Name: signals_status_fired_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX signals_status_fired_idx ON public.signals USING btree (status, fired_at);


--
-- Name: signals_volume_24h_usd_index; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX signals_volume_24h_usd_index ON public.signals USING btree (volume_24h_usd);


--
-- Name: snapshots_market_prob_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX snapshots_market_prob_idx ON public.market_snapshots USING btree (market_id, probability_yes);


--
-- Name: snapshots_market_time_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX snapshots_market_time_idx ON public.market_snapshots USING btree (market_id, snapshotted_at);


--
-- Name: snapshots_time_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX snapshots_time_idx ON public.market_snapshots USING btree (snapshotted_at);


--
-- Name: snapshots_volume_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX snapshots_volume_idx ON public.market_snapshots USING btree (market_id, volume_24h_usd);


--
-- Name: trades_edge_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX trades_edge_idx ON public.paper_trades USING btree (edge_at_entry);


--
-- Name: trades_entered_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX trades_entered_idx ON public.paper_trades USING btree (entered_at);


--
-- Name: trades_market_status_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX trades_market_status_idx ON public.paper_trades USING btree (market_id, status);


--
-- Name: trades_outcome_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX trades_outcome_idx ON public.paper_trades USING btree (outcome);


--
-- Name: trades_pnl_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX trades_pnl_idx ON public.paper_trades USING btree (pnl_usd);


--
-- Name: trades_roi_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX trades_roi_idx ON public.paper_trades USING btree (roi);


--
-- Name: trades_status_entered_idx; Type: INDEX; Schema: public; Owner: sikapiapsby
--

CREATE INDEX trades_status_entered_idx ON public.paper_trades USING btree (status, entered_at);


--
-- Name: ai_predictions ai_predictions_market_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.ai_predictions
    ADD CONSTRAINT ai_predictions_market_id_foreign FOREIGN KEY (market_id) REFERENCES public.markets(id) ON DELETE CASCADE;


--
-- Name: market_daily_stats market_daily_stats_market_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.market_daily_stats
    ADD CONSTRAINT market_daily_stats_market_id_foreign FOREIGN KEY (market_id) REFERENCES public.markets(id) ON DELETE CASCADE;


--
-- Name: market_outcomes market_outcomes_market_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.market_outcomes
    ADD CONSTRAINT market_outcomes_market_id_foreign FOREIGN KEY (market_id) REFERENCES public.markets(id) ON DELETE CASCADE;


--
-- Name: market_snapshots market_snapshots_market_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.market_snapshots
    ADD CONSTRAINT market_snapshots_market_id_foreign FOREIGN KEY (market_id) REFERENCES public.markets(id) ON DELETE CASCADE;


--
-- Name: model_has_permissions model_has_permissions_permission_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.model_has_permissions
    ADD CONSTRAINT model_has_permissions_permission_id_foreign FOREIGN KEY (permission_id) REFERENCES public.permissions(id) ON DELETE CASCADE;


--
-- Name: model_has_roles model_has_roles_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.model_has_roles
    ADD CONSTRAINT model_has_roles_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: paper_trade_history paper_trade_history_paper_trade_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.paper_trade_history
    ADD CONSTRAINT paper_trade_history_paper_trade_id_foreign FOREIGN KEY (paper_trade_id) REFERENCES public.paper_trades(id) ON DELETE CASCADE;


--
-- Name: paper_trades paper_trades_market_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.paper_trades
    ADD CONSTRAINT paper_trades_market_id_foreign FOREIGN KEY (market_id) REFERENCES public.markets(id) ON DELETE CASCADE;


--
-- Name: paper_trades paper_trades_signal_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.paper_trades
    ADD CONSTRAINT paper_trades_signal_id_foreign FOREIGN KEY (signal_id) REFERENCES public.signals(id) ON DELETE SET NULL;


--
-- Name: paper_trades paper_trades_trading_account_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.paper_trades
    ADD CONSTRAINT paper_trades_trading_account_id_foreign FOREIGN KEY (trading_account_id) REFERENCES public.trading_accounts(id) ON DELETE CASCADE;


--
-- Name: role_has_permissions role_has_permissions_permission_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_permission_id_foreign FOREIGN KEY (permission_id) REFERENCES public.permissions(id) ON DELETE CASCADE;


--
-- Name: role_has_permissions role_has_permissions_role_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.role_has_permissions
    ADD CONSTRAINT role_has_permissions_role_id_foreign FOREIGN KEY (role_id) REFERENCES public.roles(id) ON DELETE CASCADE;


--
-- Name: signals signals_ai_prediction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.signals
    ADD CONSTRAINT signals_ai_prediction_id_foreign FOREIGN KEY (ai_prediction_id) REFERENCES public.ai_predictions(id) ON DELETE SET NULL;


--
-- Name: signals signals_market_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.signals
    ADD CONSTRAINT signals_market_id_foreign FOREIGN KEY (market_id) REFERENCES public.markets(id) ON DELETE CASCADE;


--
-- Name: trading_accounts trading_accounts_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: sikapiapsby
--

ALTER TABLE ONLY public.trading_accounts
    ADD CONSTRAINT trading_accounts_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: SCHEMA public; Type: ACL; Schema: -; Owner: sikapiapsby
--

REVOKE USAGE ON SCHEMA public FROM PUBLIC;


--
-- PostgreSQL database dump complete
--

\unrestrict RmZEpRex6VY1VOt9XKDbaqY9mkvsIkBogFIhsRc6lfc20UYnShGVJdAsV2PsBcZ

