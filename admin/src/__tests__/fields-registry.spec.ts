import { describe, it, expect } from 'vitest'
import { fieldComponent } from '@/fields/registry'
import type { FieldDef } from '@/fields/types'
import StringField from '@/fields/components/StringField.vue'
import TextField from '@/fields/components/TextField.vue'
import NumberField from '@/fields/components/NumberField.vue'
import BooleanField from '@/fields/components/BooleanField.vue'
import DatetimeField from '@/fields/components/DatetimeField.vue'
import EnumField from '@/fields/components/EnumField.vue'
import AssetField from '@/fields/components/AssetField.vue'
import ReferenceField from '@/fields/components/ReferenceField.vue'
import JsonField from '@/fields/components/JsonField.vue'

describe('field registry', () => {
  it('maps every field type to its component', () => {
    const cases: Array<[FieldDef['type'], unknown]> = [
      ['string', StringField],
      ['text', TextField],
      ['number', NumberField],
      ['boolean', BooleanField],
      ['datetime', DatetimeField],
      ['enum', EnumField],
      ['asset', AssetField],
      ['reference', ReferenceField],
      ['json', JsonField],
    ]
    for (const [type, component] of cases) {
      expect(fieldComponent(type)).toBe(component)
    }
  })

  it('degrades an unknown field type to the string component', () => {
    expect(fieldComponent('weird' as FieldDef['type'])).toBe(StringField)
  })
})
