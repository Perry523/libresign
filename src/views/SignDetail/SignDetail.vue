<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import DragResize from 'vue-drag-resize'
import { get, pick, find, map, cloneDeep } from 'lodash-es'
import Content from '@nextcloud/vue/dist/Components/Content'
import { service as signService, SIGN_STATUS } from '../../domains/sign'
import Sidebar from './partials/Sidebar.vue'
import PageNavigation from './partials/PageNavigation.vue'
import { showResponseError } from '../../helpers/errors'
import ActionButton from '@nextcloud/vue/dist/Components/ActionButton'
import { SignatureImageDimensions } from '../../Components/Draw'
import Chip from '../../Components/Chip.vue'

const emptyElement = () => {
	return {
		coordinates: {
			page: 1,
			left: 100,
			top: 100,
			height: SignatureImageDimensions.height,
			width: SignatureImageDimensions.width,
		},
		elementId: 0,
	}
}

const emptySignerData = () => ({
	signed: null,
	displayName: '',
	fullName: null,
	me: false,
	fileUserId: 0,
	email: '',
	element: emptyElement(),
})

const deepCopy = val => JSON.parse(JSON.stringify(val))

export default {
	name: 'SignDetail',
	components: {
		Content,
		DragResize,
		Sidebar,
		PageNavigation,
		ActionButton,
		Chip,
	},
	data() {
		return {
			signers: [],
			document: {
				id: '',
				name: '',
				signers: [],
				pages: [],
				visibleElements: [],
			},
			currentSigner: emptySignerData(),
		}
	},
	computed: {
		uuid() {
			return this.$route.params.uuid || ''
		},
		pageIndex() {
			return this.currentSigner.element.coordinates.page - 1
		},
		pages() {
			return get(this.document, 'pages', [])
		},

		status() {
			return Number(get(this.document, 'status', -1))
		},
		statusLabel() {
			return get(this.document, 'statusText', '')
		},
		isDraft() {
			return this.status === SIGN_STATUS.DRAFT
		},
		page() {
			return this.pages[this.pageIndex] || {
				url: '',
				resolution: {
					h: 0,
					w: 0,
				},
			}
		},
		pageDimensions() {
			const { w, h } = this.page.resolution
			return {
				height: h,
				width: w,
				css: {
					height: `${Math.ceil(h)}px`,
					width: `${Math.ceil(w)}px`,
				},
			}
		},
		hasSignerSelected() {
			return !!this.currentSigner.email
		},
		editingElement() {
			return this.currentSigner.element.elementId > 0
		},
	},
	async mounted() {
		this.loadDocument()
		this.$refs.img.setAttribute('draggable', false)
	},
	methods: {
		onError(err) {
			if (err.response) {
				return showResponseError(err.response)
			}

			return showError(err.message)
		},
		updateSigners() {
			const { fileUserId } = this.currentSigner

			this.currentSigner = emptySignerData()

			const [signers, visibleElements] = deepCopy([this.document.signers, this.document.visibleElements])

			this.signers = map(signers, signer => {
				const element = find(visibleElements, (el) => {
					return el.fileUserId === signer.fileUserId
				})

				const row = {
					...signer,
					element: emptyElement(),
				}

				if (element) {
					const coordinates = pick(element.coordinates, ['top', 'left', 'width', 'height', 'page'])

					row.element = {
						elementId: element.elementId,
						coordinates,
					}
				}

				return row
			})

			this.$nextTick(() => {
				if (fileUserId === 0) {
					return
				}

				const current = this.signers.find(signer => signer.fileUserId === fileUserId)

				this.onSelectSigner({ ...current })
			})
		},
		resize(newRect) {
			const { coordinates } = this.currentSigner.element

			this.currentSigner.element.coordinates = {
				...coordinates,
				...newRect,
			}
		},
		onSelectSigner(signer) {
			const page = this.pageIndex + 1

			this.currentSigner = emptySignerData()
			this.$nextTick(() => {
				this.currentSigner = cloneDeep(signer)

				if (signer.element.elementId === 0) {
					this.currentSigner.element.coordinates.page = page
				}
			})
		},
		async publish() {
			const allow = confirm(t('libresign', 'Request signatures?'))

			if (!allow) {
				return
			}

			try {
				await signService.changeRegisterStatus(this.document.fileId, SIGN_STATUS.ABLE_TO_SIGN)
				this.$nextTick(() => this.loadDocument())
			} catch (err) {
				this.onError(err)
			}
		},
		async loadDocument() {
			try {
				this.signers = []
				this.document = await signService.validateByUUID(this.uuid)
				this.$nextTick(() => this.updateSigners())
			} catch (err) {
				this.onError(err)
			}
		},
		async sendNotify(signer) {
			try {
				const data = await signService.notifySigner(this.document.fileId, signer.email)
				showSuccess(t('libresign', data.message))
			} catch (err) {
				this.onError(err)
			}

		},
		async removeSigner(signer) {
			const result = confirm(t('libresign', 'Are you sure you want to exclude user {email} from the request?', { email: signer.email }))

			if (result === false) {
				return
			}

			try {
				const data = await signService.removeSigner(this.document.fileId, signer.fileUserId)
				showSuccess(t('libresign', data.message))
			} catch (err) {
				this.onError(err)
			}
		},
		async saveElement() {
			const { element, fileUserId } = this.currentSigner

			const payload = {
				coordinates: {
					...element.coordinates,
					page: element.coordinates.page,
				},
				type: 'signature',
				fileUserId,
			}

			try {
				this.editingElement
					? await signService.updateElement(this.uuid, element.elementId, payload)
					: await signService.addElement(this.uuid, payload)
				showSuccess(t('libresign', 'Element created'))

				this.$nextTick(() => this.loadDocument())
			} catch (err) {
				this.onError(err)
			}
		},
	},
}
</script>

<template>
	<Content class="view-sign-detail" app-name="libresign">
		<div class="sign-details">
			<h2>
				{{ document.name }}
				<br>
				<Chip :state="isDraft ? 'warning' : 'default'">
					{{ statusLabel }}
				</Chip>
			</h2>
			<p>
				<small>
					{{ t('libresign', 'Select each signer to define their signature positions') }}
				</small>
			</p>
			<Sidebar class="view-sign-detail--sidebar"
				:signers="signers"
				@select:signer="onSelectSigner">
				<template #actions="{signer}">
					<ActionButton v-if="!signer.signed" icon="icon-comment" @click="sendNotify(signer)">
						{{ t('libresign', 'Send reminder') }}
					</ActionButton>
					<ActionButton v-if="!signer.signed" icon="icon-delete" @click="removeSigner(signer)">
						{{ t('libresign', 'Remove') }}
					</ActionButton>
				</template>

				<button v-if="isDraft" class="primary publish-btn" @click="publish">
					{{ t('libresign', 'Request') }}
				</button>
			</Sidebar>
		</div>
		<div class="image-page">
			<!-- <canvas ref="canvas" :width="page.resolution.w" :height="page.resolution.h" /> -->
			<!-- <div :style="{ width: `${page.resolution.w}px`, height: `${page.resolution.h}px`, background: 'red' }">
				<img :src="page.url">
			</div> -->
			<PageNavigation
				v-model="currentSigner.element.coordinates.page"
				v-bind="{ pages }"
				:width="pageDimensions.css.width" />
			<div class="image-page--main">
				<div
					class="image-page--container"
					:style="{ '--page-img-w': pageDimensions.css.width, '--page-img-h': pageDimensions.css.height }">
					<DragResize
						v-if="hasSignerSelected"
						parent-limitation
						:is-active="true"
						:is-resizable="true"
						:w="currentSigner.element.coordinates.width"
						:h="currentSigner.element.coordinates.height"
						:x="currentSigner.element.coordinates.left"
						:y="currentSigner.element.coordinates.top"
						@resizing="resize"
						@dragging="resize">
						<div class="image-page--element">
							{{ currentSigner.email }}
						</div>
						<div class="image-page--action">
							<button class="primary" @click="saveElement">
								{{ t('libresign', editingElement ? 'Update' : 'Save') }}
							</button>
						</div>
					</DragResize>
					<img ref="img" :src="page.url">
				</div>
			</div>
		</div>
	</Content>
</template>

<style lang="scss" scoped>
.sign-details {
	margin-left: 5px;
}

.view-sign-detail {
	&--sidebar {
		width: 300px;
	}
}

.image-page {
	width: 100%;
	margin: 0.5em;
	&--main {
		position: relative;
	}
	&--element {
		width: 100%;
		height: 100%;
		display: flex;
		position: absolute;
		cursor: grab;
		background: rgba(0, 0, 0, 0.3);
		color: #FFF;
		font-weight: bold;
		justify-content: space-around;
		align-items: center;
		flex-direction: row;
		&:active {
			cursor: grabbing;
		}
	}
	&--action {
		width: 100%;
		position: absolute;
		top: 100%;
	}
	&--container {
		border-color: #000;
		border-style: solid;
		border-width: thin;
		width: var(--page-img-w);
		height: var(--page-img-h);
		left: 0;
		top: 0;
		&, img {
			user-select: none;
			outline: 0;
		}
		img {
			max-width: 100%;
		}
	}
}

.publish-btn {
	width: 100%;
}
</style>
