// One field in a content type's schema. Mirrors the backend FieldDefinition (the `type` union is
// the same set the OpenAPI schema enumerates for content-type field definitions).
export interface FieldDef {
  name: string
  type:
    | 'string'
    | 'text'
    | 'number'
    | 'boolean'
    | 'datetime'
    | 'enum'
    | 'reference'
    | 'asset'
    | 'json'
  required?: boolean
  enum?: string[]
}
