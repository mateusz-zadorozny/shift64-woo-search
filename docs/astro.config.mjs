import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';

export default defineConfig({
  integrations: [
    starlight({
      title: 'Shift64 Woo Search',
      description: 'Documentation for the Shift64 Woo Search WordPress plugin.',
      sidebar: [
        {
          label: 'Start here',
          items: [
            { label: 'Introduction', slug: 'index' },
            { label: 'Requirements', slug: 'getting-started/requirements' },
          ],
        },
      ],
    }),
  ],
});
