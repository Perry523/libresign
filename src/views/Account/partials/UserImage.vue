<script>
import Avatar from '@nextcloud/vue/dist/Components/Avatar'

export default {
	name: 'UserImage',
	components: {
		Avatar,
	},
	props: {
		user: {
			type: Object,
			required: true,
		},
	},
}
</script>
<template>
	<div class="user-image">
		<div class="user-image-label">
			<h1>{{ t('libresign', 'Profile picture') }}</h1>
			<div class="icons icon-contacts-dark" />
		</div>

		<Avatar :show-user-status="false"
			:size="145"
			class="user-avatar"
			:user="user.uid"
			:display-name="user.displayName" />
	</div>
</template>

<style lang="scss" scoped>
.user-image {
	display: flex;
	width: 100%;
	flex-direction: column;
	align-items: center;

	h1{
		align-self: flex-start;
	}

	.user-image-label{
		display: flex;
		flex-direction: row;
		align-self: flex-start;
		margin-bottom: 20px;

		h1{
			margin-right: 10px;
		}

		.icons{
			opacity: 0.7;
		}
	}
}
</style>
