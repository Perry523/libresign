<template>
	<div class="container">
		<div class="container-request">
			<header>
				<h1>{{ t('libresign', 'Request Signatures') }}</h1>
				<p>{{ t('libresign', 'Choose the file to request signatures.') }}</p>
			</header>
			<div class="content-request">
				<File
					v-show="!isEmptyFile"
					:file="file"
					status="none"
					@sidebar="setSidebarStatus(true)" />
				<button class="icon icon-folder" @click="getFile()">
					{{ t('libresign', 'Choose from Files') }}
				</button>
			</div>
		</div>
		<AppSidebar v-if="getSidebarStatus"
			ref="sidebar"
			:class="{'app-sidebar--without-background lb-ls-root': 'lb-ls-root'}"
			:title="file.name"
			:active="file.name"
			:subtitle="t('libresign', 'Enter the emails that will receive the request')"
			:header="false"
			name="sidebar"
			icon="icon-rename"
			@close="setSidebarStatus(false)">
			<EmptyContent v-show="canRequest" class="empty-content">
				<template #desc>
					<p>
						{{ t('libresign', 'Signatures for this document have already been requested') }}
					</p>
				</template>
			</EmptyContent>
			<AppSidebarTab
				v-show="!canRequest"
				id="request"
				:name="t('libresign', 'Add users')"
				icon="icon-rename">
				<Users ref="request" :fileinfo="file" @request:signatures="send" />
			</AppSidebarTab>
		</AppSidebar>
	</div>
</template>
<script>
import AppSidebar from '@nextcloud/vue/dist/Components/AppSidebar'
import EmptyContent from '@nextcloud/vue/dist/Components/EmptyContent'
import AppSidebarTab from '@nextcloud/vue/dist/Components/AppSidebarTab'
import { getFilePickerBuilder } from '@nextcloud/dialogs'
import Users from '../Components/Request'
import File from '../Components/File/File.vue'
import { mapActions, mapGetters } from 'vuex'

export default {
	name: 'Request',
	components: {
		AppSidebar,
		AppSidebarTab,
		Users,
		EmptyContent,
		File,
	},
	data() {
		return {
			loading: false,
			file: {},
			signers: [],
		}
	},
	computed: {
		...mapGetters({ getSidebarStatus: 'sidebar/getStatus', fileSigners: 'validate/getSigners' }),
		isEmptyFile() {
			return Object.keys(this.file).length === 0
		},
		canRequest() {
			return this.fileSigners.length > 0
		},
	},
	beforeDestroy() {
		this.resetSidebarStatus()
		this.resetValidateFile()
	},
	methods: {
		...mapActions({
			resetSidebarStatus: 'sidebar/RESET',
			setSidebarStatus: 'sidebar/setStatus',
			requestNewSign: 'sign/REQUEST',
			resetValidateFile: 'validate/RESET',
			validateFile: 'validate/VALIDATE_BY_ID',
		}),
		async send(users) {
			try {
				const name = this.file.name.split('.pdf')[0]
				this.requestNewSign({ fileId: this.file.id, name, users })
				this.clear()
			} catch {
				console.error('error')
			}
		},
		clear() {
			this.file = {}
			this.setSidebarStatus(false)
			this.$refs.request.clearList()
		},
		getFile() {
			const picker = getFilePickerBuilder(t('libresign', 'Select your file'))
				.setMultiSelect(false)
				.setMimeTypeFilter('application/pdf')
				.setModal(true)
				.setType(1)
				.allowDirectories()
				.build()

			return picker.pick()
				.then(path => {
					OC.dialogs.filelist.forEach(async file => {
						const indice = path.split('/').indexOf(file.name)
						if (path.startsWith('/')) {
							if (file.name === path.split('/')[indice]) {
								this.file = file
								this.setSidebarStatus(true)
								await this.validateFile(file.id)
							}
						}
					})
				})
		},
		changeTab(changeId) {
			this.tabId = changeId
		},
	},
}
</script>

<style lang="scss" scoped>
.container{
	display: flex;
	flex-direction: row;
	justify-content: center;
	align-items: center;
	width: 100%;
	height: 100%;
}

.container-request {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	width: 100%;
	max-width: 100%;
	text-align: center;

	header {
		margin-bottom: 2.5rem;

		h1 {
			font-size: 45px;
			margin-bottom: 1rem;
		}

		p {
			font-size: 15px;
		}
	}

	.content-request{
		display: flex;
		flex-direction: column;
	}
}

.empty-content{
	p{
		margin: 10px;
	}
}

button {
	background-position-x: 8%;
	padding: 13px 13px 13px 45px;
}
</style>
