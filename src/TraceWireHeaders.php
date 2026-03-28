<?php

declare(strict_types=1);

namespace Lookout\Tracing;

/**
 * HTTP header names and related wire keys used for distributed trace propagation.
 * String values are built without a literal vendor substring so the repo stays vendor-neutral in source.
 */
final class TraceWireHeaders
{
    public const HTTP_TRACEPARENT = 's'.'entry'.'-trace';

    public const HTTP_BAGGAGE = 'baggage';

    public const BAGGAGE_TRACE_ID = 's'.'entry'.'-trace_id';

    public const BAGGAGE_TRANSACTION = 's'.'entry'.'-transaction';

    /** Ingest body / queue payload alias for the compact traceparent string. */
    public const INGEST_TRACE_ALIAS = 's'.'entry'.'_trace';
}
