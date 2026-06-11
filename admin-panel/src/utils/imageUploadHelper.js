/**
 * Admin Panel 图片上传服务
 * 与用户端使用相同的 WebDAV/图床配置
 */

const getUploadConfig = () => {
  const defaultCfg = {
    enabled: true,
    uploadMethod: 'imagebed',
    maxSize: 5 * 1024 * 1024,
    webdav: {
      serverUrl: '',
      username: '',
      password: '',
      uploadPath: '/images',
      publicUrl: ''
    },
    imageBeds: [],
    imageBed: {
      type: 'imgbb',
      apiUrl: 'https://api.imgbb.com/1/upload',
      apiKey: ''
    }
  };

  if (typeof window !== 'undefined' && window.__SYS_CFG__ && window.__SYS_CFG__.TICKET_CONFIG) {
    const sysTicketConfig = window.__SYS_CFG__.TICKET_CONFIG;
    if (sysTicketConfig.imageUpload) {
      return {
        ...defaultCfg,
        ...sysTicketConfig.imageUpload
      };
    }
  }
  return defaultCfg;
};

const generateUniqueFileName = (file) => {
  const timestamp = Date.now();
  const random = Math.random().toString(36).substring(2, 8);
  const extension = file.name.split('.').pop();
  return `${timestamp}_${random}.${extension}`;
};

const uploadToWebDAV = async (file, config) => {
  const { webdav } = config;
  if (!webdav.serverUrl || !webdav.username || !webdav.password) {
    throw new Error('WebDAV 配置不完整');
  }

  const fileName = generateUniqueFileName(file);
  const uploadPath = `${webdav.uploadPath || '/images'}/${fileName}`;
  const fullUrl = `${webdav.serverUrl}${uploadPath}`;
  const credentials = btoa(`${webdav.username}:${webdav.password}`);

  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.addEventListener('load', () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        const publicUrl = `${webdav.publicUrl}/${fileName}`;
        resolve({ url: publicUrl, markdown: `![图片](${publicUrl})` });
      } else {
        reject(new Error(`WebDAV 上传失败: ${xhr.status} ${xhr.statusText}`));
      }
    });
    xhr.addEventListener('error', () => reject(new Error('WebDAV 上传网络错误')));
    xhr.open('PUT', fullUrl);
    xhr.setRequestHeader('Authorization', `Basic ${credentials}`);
    xhr.setRequestHeader('Content-Type', file.type);
    xhr.send(file);
  });
};

const uploadToSpecificImageBed = async (file, imageBed) => {
  const formData = new FormData();
  let fileField = 'image';
  
  if (imageBed.type === 'smms') {
    fileField = 'smfile';
  } else if (imageBed.type === 'lsky') {
    fileField = 'file';
  } else if (imageBed.type === 'chevereto') {
    fileField = 'source';
  }

  formData.append(fileField, file);

  if (imageBed.type === 'imgbb' && imageBed.apiKey) {
    formData.append('key', imageBed.apiKey);
  }

  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.addEventListener('load', () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        try {
          const result = JSON.parse(xhr.responseText);
          let imageUrl = null;
          
          if (imageBed.type === 'imgbb' && result.success) {
            imageUrl = result.data.url;
          } else if (imageBed.type === 'smms' && result.success) {
            imageUrl = result.data.url;
          } else if (imageBed.type === 'chevereto' && result.status_code === 200) {
            imageUrl = result.data.url;
          } else if (imageBed.type === 'lsky' && result.status) {
            imageUrl = result.data.url;
          } else {
            imageUrl = result.url || result.data?.url || result.link || result.src;
          }

          if (imageUrl) {
            resolve({ url: imageUrl, markdown: `![图片](${imageUrl})` });
          } else {
            reject(new Error(result.message || result.error?.message || '无法获取图片地址'));
          }
        } catch (e) {
          reject(new Error('解析响应失败'));
        }
      } else {
        reject(new Error(`图床上传失败: ${xhr.status}`));
      }
    });
    xhr.addEventListener('error', () => reject(new Error('图床上传网络错误')));
    xhr.open('POST', imageBed.apiUrl);
    
    if (imageBed.headers) {
      Object.keys(imageBed.headers).forEach(key => {
        xhr.setRequestHeader(key, imageBed.headers[key]);
      });
    } else if (imageBed.apiKey && imageBed.type === 'smms') {
      xhr.setRequestHeader('Authorization', imageBed.apiKey);
    }
    
    xhr.send(formData);
  });
};

const uploadToImageBed = async (file, config) => {
  const beds = config.imageBeds || [];
  const enabledBeds = beds.filter(b => b.enabled).sort((a, b) => a.priority - b.priority);

  if (enabledBeds.length === 0) {
    // Fallback to legacy single imageBed config
    const legacyBed = config.imageBed;
    if (legacyBed && legacyBed.apiUrl) {
      return uploadToSpecificImageBed(file, legacyBed);
    }
    throw new Error('未配置任何可用图床');
  }

  let lastError = null;
  for (const bed of enabledBeds) {
    try {
      return await uploadToSpecificImageBed(file, bed);
    } catch (e) {
      lastError = e;
      console.warn(`图床 ${bed.name} 上传失败，尝试下一个...`, e);
    }
  }
  throw lastError || new Error('所有图床均上传失败');
};

export const uploadImage = async (file) => {
  const config = getUploadConfig();
  if (!config.enabled) {
    throw new Error('图片上传功能未启用');
  }
  if (!file.type.startsWith('image/')) {
    throw new Error('只支持图片文件');
  }
  if (file.size > config.maxSize) {
    throw new Error(`图片大小不能超过 ${config.maxSize / (1024 * 1024)}MB`);
  }

  if (config.uploadMethod === 'webdav') {
    return uploadToWebDAV(file, config);
  } else {
    return uploadToImageBed(file, config);
  }
};
