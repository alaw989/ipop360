# Iteration Notes

## Goal
Add SheetTitle and SheetDescription to the CuisinePicker.vue and LocationPicker.vue bottom sheets so they clear the reka-ui DialogTitle/DialogDescription missing-title a11y console warnings and are announced properly by screen readers. Follow the exact pattern already used in TopNav.vue, which imports SheetTitle/SheetDescription from '@/components/ui/sheet' and renders <SheetTitle class='text-sm'>Menu</SheetTitle> plus <SheetDescription class='sr-only'>...text...</SheetDescription> inside the SheetContent. Give each picker a meaningful accessible title/description reflecting its purpose (e.g. choose a cuisine / choose a city). Add or extend vitest specs asserting the a11y warnings are gone and the title/description render. Keep all existing tests green.

## State
Added SheetTitle + sr-only SheetDescription to CuisinePicker & LocationPicker bottom sheets (matches TopNav pattern). Added mobile-mode vitest asserts. 1094 tests green.

## Log
- Added SheetTitle/SheetDescription to CuisinePicker & LocationPicker SheetContent; spec stubs + mobile title/description asserts.
