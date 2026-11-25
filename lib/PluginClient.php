<?php

declare(strict_types=1);

/**
 * Browser bridge passed to a studio script.
 *
 * A script declared by workspace() or sideTab() is an ES module. Its default
 * export is boot(sneeze). PluginClient::reference() describes that object.
 */
class PluginClient
{
    /**
     * @return list<array{name: string, summary: string, signature: string}>
     */
    public static function reference(): array
    {
        return [
            [
                'name' => 'boot',
                'summary' => 'Default export of the module named by a workspace or side tab script.',
                'signature' => 'export default function boot(sneeze): void | Promise<void>',
            ],
            [
                'name' => 'sneeze.api',
                'summary' => 'Fetch helper used by the studio. Sends the CSRF header on requests that are not GET. JSON routes have no client timeout. They still end when PHP max_execution_time does.',
                'signature' => 'api(path: string, options?: { method?: string, body?: string | FormData, headers?: object }): Promise<any>',
            ],
            [
                'name' => 'sneeze.stream',
                'summary' => 'Read a plugin route registered with stream: true. Parses Server-Sent Events and calls onEvent for each JSON payload. The request holds one PHP worker until the generator finishes.',
                'signature' => 'stream(path: string, options?: { method?: string, body?: string, headers?: object }, onEvent?: (event: object) => void): Promise<object[]>',
            ],
            [
                'name' => 'sneeze.plugin',
                'summary' => 'The plugin that registered the script.',
                'signature' => '{ id: string, name: string }',
            ],
            [
                'name' => 'sneeze.studio.getState',
                'summary' => 'JSON copy of the open page, the selected block id, templates, and block types.',
                'signature' => '(): { page: object | null, selectedId: number | string | null, templates: object[], blockTypes: object[] }',
            ],
            [
                'name' => 'sneeze.studio.applyPage',
                'summary' => 'Merge a partial into the open draft, refresh the preview immediately, and mark the draft unsaved. The studio autosave writes it. Pushes an undo checkpoint unless options.checkpoint is false.',
                'signature' => '(partial: { title?: string, slug?: string, seo_title?: string, seo_description?: string, template_id?: number, custom_css?: string, custom_js?: string, header_mode?: string, footer_mode?: string, header_html?: string, footer_html?: string, disabled_stylesheet_ids?: number[], disabled_script_ids?: number[], blocks?: object[] }, options?: { checkpoint?: boolean }): boolean',
            ],
            [
                'name' => 'sneeze.studio.insertBlock',
                'summary' => 'Insert a block into the open draft and refresh the preview. typeId or slug picks the block type. index counts blocks in the same slot. A missing index appends.',
                'signature' => '(spec: { typeId?: number, slug?: string, parentId?: number|string|null, slot?: string, index?: number, data?: object }, options?: { checkpoint?: boolean }): number|string|null',
            ],
            [
                'name' => 'sneeze.studio.updateBlockData',
                'summary' => 'Replace one block data object in the open draft. Sibling blocks stay. Refreshes the preview.',
                'signature' => '(id: number|string, data: object, options?: { checkpoint?: boolean }): boolean',
            ],
            [
                'name' => 'sneeze.studio.patchBlockData',
                'summary' => 'Merge keys into one block data object in the open draft. Other keys and sibling blocks stay. Refreshes the preview.',
                'signature' => '(id: number|string, partial: object, options?: { checkpoint?: boolean }): boolean',
            ],
            [
                'name' => 'sneeze.studio.removeBlock',
                'summary' => 'Remove a block and its children from the open draft and refresh the preview.',
                'signature' => '(id: number|string, options?: { checkpoint?: boolean }): boolean',
            ],
            [
                'name' => 'sneeze.studio.selectBlock',
                'summary' => 'Select a block in the outline and highlight it in the preview.',
                'signature' => '(id: number|string): void',
            ],
            [
                'name' => 'sneeze.studio.openPage',
                'summary' => 'Open a page in the studio by id. Clears the undo stack.',
                'signature' => '(id: number): Promise<void>',
            ],
            [
                'name' => 'sneeze.studio.openSideTab',
                'summary' => 'Show a studio side tab: blocks, write, code, or a plugin tab id plugin-{pluginId}-{tabId}.',
                'signature' => '(name: "blocks" | "write" | "code" | string): boolean',
            ],
            [
                'name' => 'sneeze.studio.openWorkspace',
                'summary' => 'Show a studio workspace: studio, library, design, media, cms, users, plugins, settings, or a plugin workspace id.',
                'signature' => '(name: string): boolean',
            ],
            [
                'name' => 'sneeze.studio.pushCheckpoint',
                'summary' => 'Remember the open page so undoCheckpoint can restore it. The stack holds 20 snapshots in this browser tab.',
                'signature' => '(): boolean',
            ],
            [
                'name' => 'sneeze.studio.undoCheckpoint',
                'summary' => 'Restore the previous open-page snapshot, refresh the preview, and mark the draft unsaved.',
                'signature' => '(): boolean',
            ],
            [
                'name' => 'sneeze.studio.notify',
                'summary' => 'Show a toast in the studio.',
                'signature' => '(message: string, isError?: boolean): void',
            ],
            [
                'name' => 'sneeze.studio.on',
                'summary' => 'Subscribe to studio events. page receives the opened page, selection receives {id, block}, saved receives the page after a draft save. Returns an unsubscribe function.',
                'signature' => '(event: "page" | "selection" | "saved", fn: (payload: any) => void): () => void',
            ],
        ];
    }
}
