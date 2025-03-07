<script>
import ListItem from '@nextcloud/vue/dist/Components/ListItem'
import Avatar from '@nextcloud/vue/dist/Components/Avatar'
import { fromUnixTime } from 'date-fns'

export default {
	name: 'SignerRow',
	components: {
		ListItem,
		Avatar,
	},
	props: {
		signer: {
			type: Object,
			required: true,
		},
		to: {
			type: Object,
			required: false,
			default: undefined,
		},
	},
	computed: {
		displayName() {
			const { signer } = this

			if (signer.displayName) {
				return signer.displayName
			}

			if (signer.fullName) {
				return signer.fullName
			}

			if (signer.email) {
				return signer.email
			}

			return t('libresign', 'Account not exist')
		},
		status() {
			const { signer } = this
			return signer.signed ? 'signed' : 'pending'
		},
		signDate() {
			const { signer } = this

			return signer.signed
				? fromUnixTime(signer.signed).toLocaleDateString()
				: ''
		},
		element() {
			return this.signer.element || {}
		},
		hasElement() {
			return this.element.elementId > 0
		},
	},
}
</script>

<template>
	<ListItem
		v-bind="{ to, 'counter-number': hasElement ? '📎' : undefined }"
		:title="displayName"
		:details="signDate"
		:class="`signer-row signer-row-${status}`"
		v-on="$listeners">
		<template #icon>
			<Avatar is-no-user
				:size="44"
				:user="signer.email"
				:display-name="displayName" />
		</template>
		<template #subtitle>
			<span class="signer-status">{{ status }}</span>
		</template>
		<slot v-if="$slots.actions" slot="actions" name="actions" />
	</ListItem>
</template>

<style>
	.signer-row-signed .signer-status {
		font-weight: bold;
	}

	.signer-row-pending .signer-status {
		color: var(--color-warning, #eca700)
	}
</style>
