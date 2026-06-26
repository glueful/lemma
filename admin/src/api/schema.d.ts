export interface paths {
  '/content-types': {
    parameters: {
      query?: never
      header?: never
      path?: never
      cookie?: never
    }
    /**
     * List content types
     * @description Each item includes its full field schema.
     */
    get: operations['getV1AdminContenttypes']
    put?: never
    /**
     * Create a content type
     * @description `slug` must be a unique lowercase identifier. Filterable-field indexes are built out-of-band after commit.
     */
    post: operations['postV1AdminContenttypes']
    delete?: never
    options?: never
    head?: never
    patch?: never
    trace?: never
  }
  '/entries': {
    parameters: {
      query?: never
      header?: never
      path?: never
      cookie?: never
    }
    /**
     * List entries of a content type (draft-inclusive)
     * @description Returns a page of entries for the content type named by `type` (slug), INCLUDING drafts/scheduled/unpublished entries (this is the admin authoring list, not the published delivery feed). Each row has a derived `display_title`, editorial `status` (draft|scheduled|published), the `locales` present, and `updated_at`. Offset paged via `page`/`perPage`; `q` filters on the display title. Requires the `lemma.entries.read` permission.
     */
    get: operations['getV1AdminEntries']
    put?: never
    /**
     * Create an entry
     * @description Seeds an empty draft in the given `locale` (defaults to the i18n default).
     */
    post: operations['postV1AdminEntries']
    delete?: never
    options?: never
    head?: never
    patch?: never
    trace?: never
  }
  '/content-types/{slug}': {
    parameters: {
      query?: never
      header?: never
      path?: never
      cookie?: never
    }
    /**
     * Get a content type by slug
     * @description Includes the full field schema.
     */
    get: operations['getV1AdminContenttypesBySlug']
    put?: never
    post?: never
    /**
     * Delete a content type
     * @description Soft-delete: existing entries stay in storage but the model is hidden from listing and delivery.
     */
    delete: operations['deleteV1AdminContenttypesBySlug']
    options?: never
    head?: never
    patch?: never
    trace?: never
  }
  '/content-types/{slug}/migrations': {
    parameters: {
      query?: never
      header?: never
      path?: never
      cookie?: never
    }
    /** List schema migrations for a content type */
    get: operations['getV1AdminContenttypesBySlugMigrations']
    put?: never
    /**
     * Start a destructive schema migration
     * @description Runs asynchronously. `ops` is a list of `{op:"rename",from,to}` / `{op:"delete",name}`; only one migration per type may run at a time (409).
     */
    post: operations['postV1AdminContenttypesBySlugMigrations']
    delete?: never
    options?: never
    head?: never
    patch?: never
    trace?: never
  }
  '/content-types/{slug}/migrations/{migrationUuid}': {
    parameters: {
      query?: never
      header?: never
      path?: never
      cookie?: never
    }
    /** Get one schema migration */
    get: operations['getV1AdminContenttypesBySlugMigrationsByMigrationuuid']
    put?: never
    post?: never
    delete?: never
    options?: never
    head?: never
    patch?: never
    trace?: never
  }
  '/entries/{uuid}': {
    parameters: {
      query?: never
      header?: never
      path?: never
      cookie?: never
    }
    /**
     * Get an entry
     * @description Identity and status only, not field content — use the draft endpoint for values.
     */
    get: operations['getV1AdminEntriesByUuid']
    put?: never
    post?: never
    /**
     * Delete an entry
     * @description Soft-delete; refused (409) while published content still references the entry.
     */
    delete: operations['deleteV1AdminEntriesByUuid']
    options?: never
    head?: never
    patch?: never
    trace?: never
  }
  '/entries/{uuid}/draft/{locale}': {
    parameters: {
      query?: never
      header?: never
      path?: never
      cookie?: never
    }
    /**
     * Get an entry's draft for a locale
     * @description Returns field values plus the `lock_version` to echo back on save.
     */
    get: operations['getV1AdminEntriesByUuidDraftByLocale']
    /**
     * Save an entry's draft (optimistic-locked)
     * @description Optimistic-locked: pass the `lock_version` from the last read; a stale value yields 409 carrying the current draft so the client can rebase.
     */
    put: operations['putV1AdminEntriesByUuidDraftByLocale']
    post?: never
    /**
     * Discard an entry draft
     * @description Drops the working draft only; published content is untouched.
     */
    delete: operations['deleteV1AdminEntriesByUuidDraftByLocale']
    options?: never
    head?: never
    patch?: never
    trace?: never
  }
  '/entries/{uuid}/locales': {
    parameters: {
      query?: never
      header?: never
      path?: never
      cookie?: never
    }
    /**
     * List entry locale variants
     * @description Per-locale draft, publication, and route state — the entry's translation status.
     */
    get: operations['getV1AdminEntriesByUuidLocales']
    put?: never
    post?: never
    delete?: never
    options?: never
    head?: never
    patch?: never
    trace?: never
  }
  '/entries/{uuid}/versions/{locale}': {
    parameters: {
      query?: never
      header?: never
      path?: never
      cookie?: never
    }
    /**
     * List entry versions
     * @description Immutable published versions, newest first.
     */
    get: operations['getV1AdminEntriesByUuidVersionsByLocale']
    put?: never
    post?: never
    delete?: never
    options?: never
    head?: never
    patch?: never
    trace?: never
  }
  '/entries/{uuid}/routes': {
    parameters: {
      query?: never
      header?: never
      path?: never
      cookie?: never
    }
    /**
     * List entry routes
     * @description Route slugs assigned across all the entry's locales.
     */
    get: operations['getV1AdminEntriesByUuidRoutes']
    put?: never
    post?: never
    delete?: never
    options?: never
    head?: never
    patch?: never
    trace?: never
  }
  '/content-types/{slug}/redirects': {
    parameters: {
      query?: never
      header?: never
      path?: never
      cookie?: never
    }
    /**
     * List redirects for a content type
     * @description Returns the manual redirects defined for the content type named by `slug` (optionally filtered by `?locale=`), each with its resolved target state (live/broken).
     */
    get: operations['getV1AdminContenttypesBySlugRedirects']
    put?: never
    /**
     * Create a redirect for a content type
     * @description Adds a manual SEO redirect (301/302/308) from a source slug to a target URL or entry, scoped to the content type named by `slug`.
     */
    post: operations['postV1AdminContenttypesBySlugRedirects']
    delete?: never
    options?: never
    head?: never
    patch?: never
    trace?: never
  }
  '/entries/{uuid}/schedules': {
    parameters: {
      query?: never
      header?: never
      path?: never
      cookie?: never
    }
    /** List an entry's schedules */
    get: operations['getV1AdminEntriesByUuidSchedules']
    put?: never
    post?: never
    delete?: never
    options?: never
    head?: never
    patch?: never
    trace?: never
  }
  '/entries/{uuid}/routes/{locale}': {
    parameters: {
      query?: never
      header?: never
      path?: never
      cookie?: never
    }
    get?: never
    /**
     * Assign an entry route
     * @description Replaces any existing route slug for the entry+locale.
     */
    put: operations['putV1AdminEntriesByUuidRoutesByLocale']
    post?: never
    /**
     * Remove an entry route
     * @description Idempotent — succeeds even when no route is assigned.
     */
    delete: operations['deleteV1AdminEntriesByUuidRoutesByLocale']
    options?: never
    head?: never
    patch?: never
    trace?: never
  }
  '/redirects/{uuid}': {
    parameters: {
      query?: never
      header?: never
      path?: never
      cookie?: never
    }
    get?: never
    put?: never
    post?: never
    /**
     * Delete a redirect
     * @description Removes the manual redirect identified by `uuid`.
     */
    delete: operations['deleteV1AdminRedirectsByUuid']
    options?: never
    head?: never
    patch?: never
    trace?: never
  }
  '/entries/{uuid}/schedules/{scheduleUuid}': {
    parameters: {
      query?: never
      header?: never
      path?: never
      cookie?: never
    }
    get?: never
    put?: never
    post?: never
    /** Cancel a pending schedule */
    delete: operations['deleteV1AdminEntriesByUuidSchedulesByScheduleuuid']
    options?: never
    head?: never
    patch?: never
    trace?: never
  }
  '/entries/{uuid}/locales/{locale}': {
    parameters: {
      query?: never
      header?: never
      path?: never
      cookie?: never
    }
    get?: never
    put?: never
    /**
     * Create an entry locale draft
     * @description Optionally seeds the new draft by copying the current draft from `source_locale`.
     */
    post: operations['postV1AdminEntriesByUuidLocalesByLocale']
    delete?: never
    options?: never
    head?: never
    patch?: never
    trace?: never
  }
  '/entries/{uuid}/preview/{locale}': {
    parameters: {
      query?: never
      header?: never
      path?: never
      cookie?: never
    }
    get?: never
    put?: never
    /**
     * Mint a short-lived preview token
     * @description The returned token is the bearer capability for the unauthenticated `GET /v1/preview/{token}`. An optional `version_uuid` pins a historical version instead of the current draft.
     */
    post: operations['postV1AdminEntriesByUuidPreviewByLocale']
    delete?: never
    options?: never
    head?: never
    patch?: never
    trace?: never
  }
  '/entries/{uuid}/schedules/{locale}': {
    parameters: {
      query?: never
      header?: never
      path?: never
      cookie?: never
    }
    get?: never
    put?: never
    /** Schedule a publish/unpublish */
    post: operations['postV1AdminEntriesByUuidSchedulesByLocale']
    delete?: never
    options?: never
    head?: never
    patch?: never
    trace?: never
  }
  '/entries/{uuid}/publish/{locale}': {
    parameters: {
      query?: never
      header?: never
      path?: never
      cookie?: never
    }
    get?: never
    put?: never
    /**
     * Publish an entry's draft
     * @description Snapshots the current draft into an immutable version, pins it, and makes it visible to the delivery API.
     */
    post: operations['postV1AdminEntriesByUuidPublishByLocale']
    delete?: never
    options?: never
    head?: never
    patch?: never
    trace?: never
  }
  '/entries/{uuid}/unpublish/{locale}': {
    parameters: {
      query?: never
      header?: never
      path?: never
      cookie?: never
    }
    get?: never
    put?: never
    /**
     * Unpublish an entry
     * @description Removes the publication pin (versions are retained); idempotent — succeeds even when nothing is published.
     */
    post: operations['postV1AdminEntriesByUuidUnpublishByLocale']
    delete?: never
    options?: never
    head?: never
    patch?: never
    trace?: never
  }
  '/entries/{uuid}/rollback/{locale}': {
    parameters: {
      query?: never
      header?: never
      path?: never
      cookie?: never
    }
    get?: never
    put?: never
    /**
     * Roll back to a previous version
     * @description Re-pins an existing `version_uuid` as the published version; no new version is created.
     */
    post: operations['postV1AdminEntriesByUuidRollbackByLocale']
    delete?: never
    options?: never
    head?: never
    patch?: never
    trace?: never
  }
  '/content-types/{slug}/schema': {
    parameters: {
      query?: never
      header?: never
      path?: never
      cookie?: never
    }
    get?: never
    put?: never
    post?: never
    delete?: never
    options?: never
    head?: never
    /**
     * Update a content type's field schema
     * @description Replaces the schema wholesale (not a merge) and bumps the schema version. Filterable-field indexes are rebuilt out-of-band after commit.
     */
    patch: operations['patchV1AdminContenttypesBySlugSchema']
    trace?: never
  }
}
export type webhooks = Record<string, never>
export interface components {
  schemas: {
    PaginationMeta: {
      /** @example 1 */
      current_page: number
      /** @example 25 */
      per_page: number
      /** @example 137 */
      total: number
      /** @example 6 */
      total_pages: number
      /** @example 1 */
      from?: number
      /** @example 25 */
      to?: number
      /** @example true */
      has_next_page?: boolean
      /** @example false */
      has_previous_page?: boolean
    }
    PaginationLinks: {
      /**
       * Format: uri
       * @example /api/users?page=1
       */
      first?: string
      /**
       * Format: uri
       * @example /api/users?page=6
       */
      last?: string
      /** Format: uri */
      prev?: string | null
      /** Format: uri */
      next?: string | null
    }
    SuccessResponse: {
      /** @example true */
      success: boolean
      /** @example Operation completed successfully */
      message: string
      data?: {
        [key: string]: unknown
      }
    }
    Error: {
      /**
       * @default false
       * @example false
       */
      success: boolean
      message: string
      data: {
        [key: string]: unknown
      }
    }
    ErrorResponse: {
      /** @example false */
      success: boolean
      /** @example Resource not found */
      message: string
      error: {
        /** @example 404 */
        code: number
        /**
         * @example NOT_FOUND
         * @enum {string}
         */
        error_code:
          | 'BAD_REQUEST'
          | 'UNAUTHORIZED'
          | 'FORBIDDEN'
          | 'NOT_FOUND'
          | 'METHOD_NOT_ALLOWED'
          | 'CONFLICT'
          | 'UNPROCESSABLE_ENTITY'
          | 'TOO_MANY_REQUESTS'
          | 'INTERNAL_SERVER_ERROR'
          | 'SERVICE_UNAVAILABLE'
          | 'GATEWAY_TIMEOUT'
        /**
         * Format: date-time
         * @description ISO 8601 datetime when the error occurred.
         */
        timestamp: string
        /**
         * @description Correlation identifier for tracing this request in logs.
         * @example req_abc123
         */
        request_id: string
      }
    }
    WebhookEnvelope: {
      /** @example wh_evt_abc123 */
      id: string
      /** @example user.created */
      event: string
      /** Format: date-time */
      created_at: string
      data: Record<string, never>
    }
    ValidationErrorResponse: {
      /** @example false */
      success: boolean
      /** @example Validation failed */
      message: string
      errors: {
        [key: string]: string[]
      }
    }
    LoginRequest: {
      /** @description Username or email */
      username: string
      /**
       * Format: password
       * @description User password
       */
      password: string
      /**
       * @description Keep user logged in
       * @default false
       */
      remember_me: boolean
    }
    LoginResponse: {
      success?: boolean
      message?: string
      data?: {
        /** @description JWT access token */
        access_token?: string
        /** @description JWT refresh token */
        refresh_token?: string
        /** @example Bearer */
        token_type?: string
        /** @description Token expiration time in seconds */
        expires_in?: number
        user?: {
          /** @description User unique identifier */
          id?: string
          /**
           * Format: email
           * @description Email address
           */
          email?: string
          /** @description Email verification status */
          email_verified?: boolean
          /** @description Username */
          username?: string
          /** @description Full name */
          name?: string
          /** @description First name */
          given_name?: string
          /** @description Last name */
          family_name?: string
          /** @description Profile image URL */
          picture?: string
          /** @description User locale (e.g., en-US) */
          locale?: string
          /** @description Last update timestamp (Unix epoch) */
          updated_at?: number
        }
      }
    }
    RefreshTokenRequest: {
      /** @description The refresh token to exchange for new tokens */
      refresh_token: string
    }
    User: {
      /**
       * Format: uuid
       * @description Unique user identifier
       */
      uuid?: string
      /** @description User username */
      username?: string
      /**
       * Format: email
       * @description User email address
       */
      email?: string
      /**
       * @description User account status
       * @enum {string}
       */
      status?: 'active' | 'inactive' | 'suspended'
      /**
       * Format: date-time
       * @description Account creation timestamp
       */
      created_at?: string
      /**
       * Format: date-time
       * @description Last update timestamp
       */
      updated_at?: string
    }
    CreateUserRequest: {
      /** @description Unique username */
      username: string
      /**
       * Format: email
       * @description User email address
       */
      email: string
      /**
       * Format: password
       * @description User password
       */
      password: string
    }
    UpdateUserRequest: {
      /**
       * Format: email
       * @description New email address
       */
      email?: string
      /**
       * @description New account status
       * @enum {string}
       */
      status?: 'active' | 'inactive' | 'suspended'
    }
    HealthCheckResponse: {
      /**
       * @description Overall system health status
       * @enum {string}
       */
      status?: 'healthy' | 'unhealthy'
      /**
       * Format: date-time
       * @description Health check timestamp
       */
      timestamp?: string
      services?: {
        database?: components['schemas']['ServiceHealth']
        cache?: components['schemas']['ServiceHealth']
        queue?: components['schemas']['ServiceHealth']
      }
    }
    ServiceHealth: {
      /**
       * @description Service availability status
       * @enum {string}
       */
      status?: 'up' | 'down'
      /** @description Response time in milliseconds */
      latency?: number
      /** @description Additional status information */
      message?: string
    }
    Extension: {
      /** @description Extension name */
      name?: string
      /** @description Extension version */
      version?: string
      /**
       * @description Extension status
       * @enum {string}
       */
      status?: 'enabled' | 'disabled'
      /**
       * @description Extension type
       * @enum {string}
       */
      type?: 'core' | 'optional'
      /** @description Extension description */
      description?: string
      /** @description Required dependencies */
      dependencies?: string[]
    }
    ExtensionListResponse: {
      success?: boolean
      data?: components['schemas']['Extension'][]
    }
    Notification: {
      /** @description Notification ID */
      id?: number
      /** @description Notification type */
      type?: string
      /** @description Type of entity being notified */
      notifiable_type?: string
      /** @description ID of entity being notified */
      notifiable_id?: string
      /** @description Notification payload */
      data?: Record<string, never>
      /**
       * Format: date-time
       * @description When notification was read
       */
      read_at?: string | null
      /**
       * Format: date-time
       * @description When notification was created
       */
      created_at?: string
    }
    NotificationListResponse: {
      success?: boolean
      data?: components['schemas']['Notification'][]
      meta?: components['schemas']['PaginationMeta']
    }
    FileUploadRequest: {
      /**
       * Format: binary
       * @description The file to upload
       */
      file: string
    }
    FileUploadResponse: {
      success?: boolean
      data?: {
        /** @description Uploaded file name */
        filename?: string
        /** @description File size in bytes */
        size?: number
        /** @description File MIME type */
        mime_type?: string
        /** @description File access URL */
        url?: string
      }
    }
  }
  responses: never
  parameters: never
  requestBodies: never
  headers: never
  pathItems: never
}
export type $defs = Record<string, never>
export interface operations {
  getV1AdminContenttypes: {
    parameters: {
      query?: never
      header?: never
      path?: never
      cookie?: never
    }
    requestBody?: never
    responses: {
      /** @description All content types. */
      200: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success: boolean
            message: string
            data: {
              content_types?: {
                id?: number
                uuid?: string
                slug?: string
                name?: string
                description?: string | null
                cache_ttl?: number | null
                public_delivery?: boolean
                status?: string
                schema?: {
                  name?: string
                  /** @enum {string} */
                  type?:
                    | 'string'
                    | 'text'
                    | 'number'
                    | 'boolean'
                    | 'datetime'
                    | 'enum'
                    | 'reference'
                    | 'asset'
                    | 'json'
                  required?: boolean | null
                  localized?: boolean | null
                  filterable?: boolean | null
                  filter_type?: string | null
                  enum?: string[]
                }[]
                schema_version?: number
                created_by?: string | null
                /** Format: date-time */
                created_at?: string
                /** Format: date-time */
                updated_at?: string | null
              }[]
            }
          }
        }
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  postV1AdminContenttypes: {
    parameters: {
      query?: never
      header?: never
      path?: never
      cookie?: never
    }
    requestBody: {
      content: {
        /**
         * @example {
         *       "slug": "example-slug",
         *       "name": "Jane",
         *       "description": "A short description.",
         *       "cache_ttl": "example",
         *       "public_delivery": true,
         *       "schema": "example"
         *     }
         */
        'application/json': {
          /** @description Unique lowercase content-type slug (1–160 chars). */
          slug: string
          /** @description Human-readable content-type name. */
          name: string
          /** @description Optional description of the content type. */
          description?: string | null
          /** @description Optional delivery Cache-Control max-age override in seconds. */
          cache_ttl?: number | null
          /** @description Whether published delivery routes may be read without an API key. */
          public_delivery?: boolean
          schema?: {
            name?: string
            type?: string
            required?: boolean | null
            localized?: boolean | null
            filterable?: boolean | null
            filter_type?: string | null
            enum?: string[]
          }[]
        }
      }
    }
    responses: {
      /** @description Successful response */
      200: {
        headers: {
          [name: string]: unknown
        }
        content?: never
      }
      /** @description Content type created. */
      201: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success: boolean
            message: string
            data: {
              content_type?: {
                id?: number
                uuid?: string
                slug?: string
                name?: string
                description?: string | null
                cache_ttl?: number | null
                public_delivery?: boolean
                status?: string
                schema?: {
                  name?: string
                  /** @enum {string} */
                  type?:
                    | 'string'
                    | 'text'
                    | 'number'
                    | 'boolean'
                    | 'datetime'
                    | 'enum'
                    | 'reference'
                    | 'asset'
                    | 'json'
                  required?: boolean | null
                  localized?: boolean | null
                  filterable?: boolean | null
                  filter_type?: string | null
                  enum?: string[]
                }[]
                schema_version?: number
                created_by?: string | null
                /** Format: date-time */
                created_at?: string
                /** Format: date-time */
                updated_at?: string | null
              }
            }
          }
        }
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Invalid slug/name, duplicate slug, or invalid field schema. */
      422: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  getV1AdminEntries: {
    parameters: {
      query: {
        /** @description Content type slug to list. */
        type: string
        /** @description Case-insensitive substring filter on the derived display title. */
        q?: string
        /** @description Page number (default 1). */
        page?: number
        /** @description Items per page (clamped to lemma.delivery.max_per_page; default default_per_page). */
        perPage?: number
      }
      header?: never
      path?: never
      cookie?: never
    }
    requestBody?: never
    responses: {
      /** @description A page of entries. */
      200: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success: boolean
            message: string
            data: {
              entries?: unknown[]
              total?: number
              current_page?: number
              per_page?: number
            }
          }
        }
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unknown content type slug. */
      404: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Missing/invalid `type`. */
      422: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  postV1AdminEntries: {
    parameters: {
      query?: never
      header?: never
      path?: never
      cookie?: never
    }
    requestBody: {
      content: {
        /**
         * @example {
         *       "content_type": "example",
         *       "locale": "example"
         *     }
         */
        'application/json': {
          /** @description Slug of the content type to create an entry for. */
          content_type: string
          /** @description BCP-47 locale for the seeded draft, e.g. "en". Defaults to the i18n default locale. */
          locale?: string | null
        }
      }
    }
    responses: {
      /** @description Successful response */
      200: {
        headers: {
          [name: string]: unknown
        }
        content?: never
      }
      /** @description Entry created with an empty draft. */
      201: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success: boolean
            message: string
            data: {
              entry?: {
                id?: number
                uuid?: string
                content_type_uuid?: string
                /** @enum {string} */
                status?: 'active' | 'archived' | 'deleted'
                created_by?: string | null
                /** Format: date-time */
                created_at?: string
                /** Format: date-time */
                updated_at?: string | null
              }
              draft?: {
                id?: number
                entry_uuid?: string
                locale?: string
                fields?: Record<string, never>
                schema_version?: number
                lock_version?: number
                updated_by?: string | null
                /** Format: date-time */
                updated_at?: string
              }
            }
          }
        }
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unknown content type. */
      422: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  getV1AdminContenttypesBySlug: {
    parameters: {
      query?: never
      header?: never
      path: {
        slug: string
      }
      cookie?: never
    }
    requestBody?: never
    responses: {
      /** @description The content type. */
      200: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success: boolean
            message: string
            data: {
              content_type?: {
                id?: number
                uuid?: string
                slug?: string
                name?: string
                description?: string | null
                cache_ttl?: number | null
                public_delivery?: boolean
                status?: string
                schema?: {
                  name?: string
                  /** @enum {string} */
                  type?:
                    | 'string'
                    | 'text'
                    | 'number'
                    | 'boolean'
                    | 'datetime'
                    | 'enum'
                    | 'reference'
                    | 'asset'
                    | 'json'
                  required?: boolean | null
                  localized?: boolean | null
                  filterable?: boolean | null
                  filter_type?: string | null
                  enum?: string[]
                }[]
                schema_version?: number
                created_by?: string | null
                /** Format: date-time */
                created_at?: string
                /** Format: date-time */
                updated_at?: string | null
              }
            }
          }
        }
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description No content type with that slug. */
      404: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  deleteV1AdminContenttypesBySlug: {
    parameters: {
      query?: never
      header?: never
      path: {
        slug: string
      }
      cookie?: never
    }
    requestBody?: never
    responses: {
      /** @description Content type deleted. */
      200: {
        headers: {
          [name: string]: unknown
        }
        content?: never
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description No content type with that slug. */
      404: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  getV1AdminContenttypesBySlugMigrations: {
    parameters: {
      query?: never
      header?: never
      path: {
        slug: string
      }
      cookie?: never
    }
    requestBody?: never
    responses: {
      /** @description Schema migrations for the content type. */
      200: {
        headers: {
          [name: string]: unknown
        }
        content?: never
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description No content type with that slug. */
      404: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  postV1AdminContenttypesBySlugMigrations: {
    parameters: {
      query?: never
      header?: never
      path: {
        slug: string
      }
      cookie?: never
    }
    requestBody: {
      content: {
        /**
         * @example {
         *       "ops": "example"
         *     }
         */
        'application/json': {
          ops: unknown[]
        }
      }
    }
    responses: {
      /** @description Successful response */
      200: {
        headers: {
          [name: string]: unknown
        }
        content?: never
      }
      /** @description Migration started; poll the returned migration row for progress. */
      201: {
        headers: {
          [name: string]: unknown
        }
        content?: never
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description No content type with that slug. */
      404: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description A migration is already running. */
      409: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Invalid migration operations. */
      422: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  getV1AdminContenttypesBySlugMigrationsByMigrationuuid: {
    parameters: {
      query?: never
      header?: never
      path: {
        slug: string
        migrationUuid: string
      }
      cookie?: never
    }
    requestBody?: never
    responses: {
      /** @description The migration row with progress counters and failure report. */
      200: {
        headers: {
          [name: string]: unknown
        }
        content?: never
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description No such migration. */
      404: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  getV1AdminEntriesByUuid: {
    parameters: {
      query?: never
      header?: never
      path: {
        uuid: string
      }
      cookie?: never
    }
    requestBody?: never
    responses: {
      /** @description The entry. */
      200: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success: boolean
            message: string
            data: {
              entry?: {
                id?: number
                uuid?: string
                content_type_uuid?: string
                /** @enum {string} */
                status?: 'active' | 'archived' | 'deleted'
                created_by?: string | null
                /** Format: date-time */
                created_at?: string
                /** Format: date-time */
                updated_at?: string | null
              }
            }
          }
        }
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description No entry with that UUID. */
      404: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  deleteV1AdminEntriesByUuid: {
    parameters: {
      query?: never
      header?: never
      path: {
        uuid: string
      }
      cookie?: never
    }
    requestBody?: never
    responses: {
      /** @description Entry deleted. */
      200: {
        headers: {
          [name: string]: unknown
        }
        content?: never
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description No entry with that UUID. */
      404: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Entry is referenced by published content. */
      409: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  getV1AdminEntriesByUuidDraftByLocale: {
    parameters: {
      query?: never
      header?: never
      path: {
        uuid: string
        locale: string
      }
      cookie?: never
    }
    requestBody?: never
    responses: {
      /** @description The draft. */
      200: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success: boolean
            message: string
            data: {
              draft?: {
                id?: number
                entry_uuid?: string
                locale?: string
                fields?: Record<string, never>
                schema_version?: number
                lock_version?: number
                updated_by?: string | null
                /** Format: date-time */
                updated_at?: string
              }
            }
          }
        }
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description No draft for that entry/locale. */
      404: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  putV1AdminEntriesByUuidDraftByLocale: {
    parameters: {
      query?: never
      header?: never
      path: {
        uuid: string
        locale: string
      }
      cookie?: never
    }
    requestBody?: {
      content: {
        /**
         * @example {
         *       "fields": "example",
         *       "lock_version": "example"
         *     }
         */
        'application/json': {
          /** @description Draft field values keyed by the content type's field names. */
          fields?: unknown[]
          /** @description Optimistic-lock counter echoed from the last read. */
          lock_version?: number | null
        }
      }
    }
    responses: {
      /** @description Draft saved. */
      200: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success: boolean
            message: string
            data: {
              draft?: {
                id?: number
                entry_uuid?: string
                locale?: string
                fields?: Record<string, never>
                schema_version?: number
                lock_version?: number
                updated_by?: string | null
                /** Format: date-time */
                updated_at?: string
              }
            }
          }
        }
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description No entry with that UUID. */
      404: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Stale lock_version — the draft was modified by another writer. */
      409: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Field validation failed against the content type schema. */
      422: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  deleteV1AdminEntriesByUuidDraftByLocale: {
    parameters: {
      query?: never
      header?: never
      path: {
        uuid: string
        locale: string
      }
      cookie?: never
    }
    requestBody?: never
    responses: {
      /** @description Draft discarded. */
      200: {
        headers: {
          [name: string]: unknown
        }
        content?: never
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description No draft for that entry/locale. */
      404: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  getV1AdminEntriesByUuidLocales: {
    parameters: {
      query?: never
      header?: never
      path: {
        uuid: string
      }
      cookie?: never
    }
    requestBody?: never
    responses: {
      /** @description Entry locale variants. */
      200: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success: boolean
            message: string
            data: {
              locales?: {
                locale?: string
                has_draft?: boolean
                is_published?: boolean
                route_slug?: string | null
                /** Format: date-time */
                draft_updated_at?: string | null
                /** Format: date-time */
                published_at?: string | null
              }[]
            }
          }
        }
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description No entry with that UUID. */
      404: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  getV1AdminEntriesByUuidVersionsByLocale: {
    parameters: {
      query?: never
      header?: never
      path: {
        uuid: string
        locale: string
      }
      cookie?: never
    }
    requestBody?: never
    responses: {
      /** @description Entry versions. */
      200: {
        headers: {
          [name: string]: unknown
        }
        content?: never
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  getV1AdminEntriesByUuidRoutes: {
    parameters: {
      query?: never
      header?: never
      path: {
        uuid: string
      }
      cookie?: never
    }
    requestBody?: never
    responses: {
      /** @description Entry routes. */
      200: {
        headers: {
          [name: string]: unknown
        }
        content?: never
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  getV1AdminContenttypesBySlugRedirects: {
    parameters: {
      query?: never
      header?: never
      path: {
        slug: string
      }
      cookie?: never
    }
    requestBody?: never
    responses: {
      /** @description Redirects retrieved. */
      200: {
        headers: {
          [name: string]: unknown
        }
        content?: never
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unknown content type slug. */
      404: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  postV1AdminContenttypesBySlugRedirects: {
    parameters: {
      query?: never
      header?: never
      path: {
        slug: string
      }
      cookie?: never
    }
    requestBody: {
      content: {
        /**
         * @example {
         *       "locale": "example",
         *       "source_slug": "example",
         *       "target": "example"
         *     }
         */
        'application/json': {
          locale: string
          source_slug: string
          target: unknown[]
          status?: number
        }
      }
    }
    responses: {
      /** @description Successful response */
      200: {
        headers: {
          [name: string]: unknown
        }
        content?: never
      }
      /** @description Redirect created. */
      201: {
        headers: {
          [name: string]: unknown
        }
        content?: never
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unknown content type or target. */
      404: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Source slug conflicts with a live route. */
      409: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Invalid status, unsafe target URL, or ambiguous target. */
      422: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  getV1AdminEntriesByUuidSchedules: {
    parameters: {
      query?: never
      header?: never
      path: {
        uuid: string
      }
      cookie?: never
    }
    requestBody?: never
    responses: {
      /** @description Schedules retrieved. */
      200: {
        headers: {
          [name: string]: unknown
        }
        content?: never
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  putV1AdminEntriesByUuidRoutesByLocale: {
    parameters: {
      query?: never
      header?: never
      path: {
        uuid: string
        locale: string
      }
      cookie?: never
    }
    requestBody: {
      content: {
        /**
         * @example {
         *       "slug": "example-slug"
         *     }
         */
        'application/json': {
          slug: string
        }
      }
    }
    responses: {
      /** @description Route assigned. */
      200: {
        headers: {
          [name: string]: unknown
        }
        content?: never
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description No entry with that UUID. */
      404: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Slug already in use. */
      409: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Validation failed */
      422: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            /** @example false */
            success: boolean
            message: string
            errors: {
              [key: string]: string[]
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  deleteV1AdminEntriesByUuidRoutesByLocale: {
    parameters: {
      query?: never
      header?: never
      path: {
        uuid: string
        locale: string
      }
      cookie?: never
    }
    requestBody?: never
    responses: {
      /** @description Route removed. */
      200: {
        headers: {
          [name: string]: unknown
        }
        content?: never
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  deleteV1AdminRedirectsByUuid: {
    parameters: {
      query?: never
      header?: never
      path: {
        uuid: string
      }
      cookie?: never
    }
    requestBody?: never
    responses: {
      /** @description Redirect deleted. */
      200: {
        headers: {
          [name: string]: unknown
        }
        content?: never
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description No redirect with that UUID. */
      404: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  deleteV1AdminEntriesByUuidSchedulesByScheduleuuid: {
    parameters: {
      query?: never
      header?: never
      path: {
        uuid: string
        scheduleUuid: string
      }
      cookie?: never
    }
    requestBody?: never
    responses: {
      /** @description Schedule canceled. */
      200: {
        headers: {
          [name: string]: unknown
        }
        content?: never
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Schedule is not pending. */
      409: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  postV1AdminEntriesByUuidLocalesByLocale: {
    parameters: {
      query?: never
      header?: never
      path: {
        uuid: string
        locale: string
      }
      cookie?: never
    }
    requestBody?: {
      content: {
        /**
         * @example {
         *       "source_locale": "example",
         *       "overwrite": true
         *     }
         */
        'application/json': {
          source_locale?: string | null
          overwrite?: boolean
        }
      }
    }
    responses: {
      /** @description Successful response */
      200: {
        headers: {
          [name: string]: unknown
        }
        content?: never
      }
      /** @description Locale draft created. */
      201: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success: boolean
            message: string
            data: {
              draft?: {
                id?: number
                entry_uuid?: string
                locale?: string
                fields?: Record<string, never>
                schema_version?: number
                lock_version?: number
                updated_by?: string | null
                /** Format: date-time */
                updated_at?: string
              }
            }
          }
        }
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description No entry with that UUID. */
      404: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Draft already exists. */
      409: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Invalid locale or source locale. */
      422: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  postV1AdminEntriesByUuidPreviewByLocale: {
    parameters: {
      query?: never
      header?: never
      path: {
        uuid: string
        locale: string
      }
      cookie?: never
    }
    requestBody?: {
      content: {
        /**
         * @example {
         *       "version_uuid": "example"
         *     }
         */
        'application/json': {
          /** @description UUID of a historical version to pin instead of the current draft. */
          version_uuid?: string | null
        }
      }
    }
    responses: {
      /** @description Preview token minted. */
      200: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success: boolean
            message: string
            data: {
              token?: string
              /** Format: date-time */
              expires_at?: string
              expires_in?: number
            }
          }
        }
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Validation failed */
      422: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            /** @example false */
            success: boolean
            message: string
            errors: {
              [key: string]: string[]
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  postV1AdminEntriesByUuidSchedulesByLocale: {
    parameters: {
      query?: never
      header?: never
      path: {
        uuid: string
        locale: string
      }
      cookie?: never
    }
    requestBody: {
      content: {
        /**
         * @example {
         *       "action": "example",
         *       "run_at": "example"
         *     }
         */
        'application/json': {
          action: string
          run_at: string
        }
      }
    }
    responses: {
      /** @description Successful response */
      200: {
        headers: {
          [name: string]: unknown
        }
        content?: never
      }
      /** @description Schedule created or rescheduled. */
      201: {
        headers: {
          [name: string]: unknown
        }
        content?: never
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Entry not found or deleted. */
      404: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Invalid schedule payload. */
      422: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  postV1AdminEntriesByUuidPublishByLocale: {
    parameters: {
      query?: never
      header?: never
      path: {
        uuid: string
        locale: string
      }
      cookie?: never
    }
    requestBody?: never
    responses: {
      /** @description Entry published. */
      200: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success: boolean
            message: string
            data: {
              version_uuid?: string | null
            }
          }
        }
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description No entry/draft to publish. */
      404: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Draft fields fail schema validation. */
      422: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  postV1AdminEntriesByUuidUnpublishByLocale: {
    parameters: {
      query?: never
      header?: never
      path: {
        uuid: string
        locale: string
      }
      cookie?: never
    }
    requestBody?: never
    responses: {
      /** @description Entry unpublished. */
      200: {
        headers: {
          [name: string]: unknown
        }
        content?: never
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  postV1AdminEntriesByUuidRollbackByLocale: {
    parameters: {
      query?: never
      header?: never
      path: {
        uuid: string
        locale: string
      }
      cookie?: never
    }
    requestBody: {
      content: {
        /**
         * @example {
         *       "version_uuid": "example"
         *     }
         */
        'application/json': {
          /** @description UUID of the version to re-publish. */
          version_uuid: string
        }
      }
    }
    responses: {
      /** @description Rolled back to the named version. */
      200: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success: boolean
            message: string
            data: {
              version_uuid?: string | null
            }
          }
        }
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Missing or invalid version_uuid (or it does not belong to this entry+locale). */
      422: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
  patchV1AdminContenttypesBySlugSchema: {
    parameters: {
      query?: never
      header?: never
      path: {
        slug: string
      }
      cookie?: never
    }
    requestBody: {
      content: {
        /**
         * @example {
         *       "schema": "example"
         *     }
         */
        'application/json': {
          schema: {
            name?: string
            type?: string
            required?: boolean | null
            localized?: boolean | null
            filterable?: boolean | null
            filter_type?: string | null
            enum?: string[]
          }[]
        }
      }
    }
    responses: {
      /** @description Schema updated. */
      200: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success: boolean
            message: string
            data: {
              content_type?: {
                id?: number
                uuid?: string
                slug?: string
                name?: string
                description?: string | null
                cache_ttl?: number | null
                public_delivery?: boolean
                status?: string
                schema?: {
                  name?: string
                  /** @enum {string} */
                  type?:
                    | 'string'
                    | 'text'
                    | 'number'
                    | 'boolean'
                    | 'datetime'
                    | 'enum'
                    | 'reference'
                    | 'asset'
                    | 'json'
                  required?: boolean | null
                  localized?: boolean | null
                  filterable?: boolean | null
                  filter_type?: string | null
                  enum?: string[]
                }[]
                schema_version?: number
                created_by?: string | null
                /** Format: date-time */
                created_at?: string
                /** Format: date-time */
                updated_at?: string | null
              }
            }
          }
        }
      }
      /** @description Unauthenticated. */
      401: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Forbidden. */
      403: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description No content type with that slug. */
      404: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Invalid field schema. */
      422: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
      /** @description Unexpected server error. */
      500: {
        headers: {
          [name: string]: unknown
        }
        content: {
          'application/json': {
            success?: boolean
            message?: string
            error?: {
              code?: number
              timestamp?: string
              request_id?: string
            }
          }
        }
      }
    }
  }
}
