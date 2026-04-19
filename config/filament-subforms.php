<?php

return [
    /*
     * How to derive a default label when the developer has not called ->label()
     * on a SubForm / SubFormRepeater field.
     *
     * 'resource'     — use the target Resource's model label (e.g. "Client").
     * 'relationship' — use the relationship name the field was made with.
     * null           — do not set a default; fall back to Filament's own behavior.
     */
    'default_label_from' => 'resource',
];
